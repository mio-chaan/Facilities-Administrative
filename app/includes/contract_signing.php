<?php
/** Contract signing workflow helpers. */
declare(strict_types=1);

if (!function_exists('t8_contract_signature_table_name')) {
    function t8_contract_signature_table_name(PDO $pdo): string
    {
        $pdo->query(
            'SELECT signature_version_no, document_hash, signature_hash, voided_at, voided_reason, ip_address, user_agent
             FROM team8_contract_signatures LIMIT 0'
        );
        return 'team8_contract_signatures';
    }
}

if (!function_exists('t8_contract_frozen_document')) {
    function t8_contract_frozen_document(PDO $pdo, int $contractId): array
    {
        $contractStmt = $pdo->prepare('SELECT signature_version_no, signature_document_hash FROM team8_contracts WHERE id = :id');
        $contractStmt->execute(['id' => $contractId]);
        $contract = $contractStmt->fetch(PDO::FETCH_ASSOC);
        if ($contract === false) {
            throw new RuntimeException('The contract record for the frozen version is unavailable.');
        }
        $versionNo = $contract['signature_version_no'] ?? null;
        if ($versionNo === false || $versionNo === null || (int) $versionNo <= 0) {
            throw new RuntimeException('The contract has no frozen signature version.');
        }

        $historyStmt = $pdo->prepare(
            'SELECT data_json FROM team8_contract_history WHERE contract_id = :contract_id AND version_no = :version_no'
        );
        $historyStmt->execute(['contract_id' => $contractId, 'version_no' => (int) $versionNo]);
        $dataJson = $historyStmt->fetchColumn();
        if (!is_string($dataJson)) {
            throw new RuntimeException('The frozen contract history version is unavailable.');
        }

        $documentHash = hash('sha256', $dataJson);
        $frozenHash = (string) ($contract['signature_document_hash'] ?? '');
        if ($frozenHash === '') {
            throw new RuntimeException('The frozen contract document hash is unavailable.');
        }
        if (!hash_equals($frozenHash, $documentHash)) {
            throw new RuntimeException('The frozen contract document no longer matches its stored hash.');
        }

        return [
            'version_no' => (int) $versionNo,
            'data_json' => $dataJson,
            'document_hash' => $documentHash,
        ];
    }
}

if (!function_exists('t8_contract_freeze_signature_version')) {
    function t8_contract_freeze_signature_version(PDO $pdo, int $contractId): array
    {
        if ($contractId <= 0) {
            throw new InvalidArgumentException('A valid contract is required to freeze its signature version.');
        }

        t8_contract_signature_table_name($pdo);
        $historyStmt = $pdo->prepare(
            'SELECT version_no, data_json FROM team8_contract_history
             WHERE contract_id = :contract_id ORDER BY version_no DESC LIMIT 1'
        );
        $historyStmt->execute(['contract_id' => $contractId]);
        $history = $historyStmt->fetch(PDO::FETCH_ASSOC);
        if (!$history || (int) $history['version_no'] <= 0 || !is_string($history['data_json'])) {
            throw new RuntimeException('A saved contract history version is required before signatures can be collected.');
        }

        $versionNo = (int) $history['version_no'];
        $stmt = $pdo->prepare(
            "UPDATE team8_contracts
             SET status = 'pending_signature', signature_version_no = :version_no, signature_document_hash = :document_hash
             WHERE id = :id AND status = 'pending_approval'"
        );
        $documentHash = hash('sha256', (string) $history['data_json']);
        if (!$stmt->execute(['version_no' => $versionNo, 'document_hash' => $documentHash, 'id' => $contractId])) {
            throw new RuntimeException('The contract signature version could not be frozen.');
        }
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The contract could not be moved to signature with its frozen version.');
        }

        return [
            'version_no' => $versionNo,
            'data_json' => (string) $history['data_json'],
            'document_hash' => $documentHash,
        ];
    }
}

if (!function_exists('t8_contract_signature_digest')) {
    function t8_contract_signature_digest(
        int $contractId,
        int $partyId,
        string $documentHash,
        string $signedAt,
        string $signerName,
        string $signerEmail
    ): string {
        $payload = json_encode([
            'contract_id' => $contractId,
            'party_id' => $partyId,
            'document_hash' => $documentHash,
            'signed_at' => $signedAt,
            'signer_name' => trim($signerName),
            'signer_email' => strtolower(trim($signerEmail)),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }
}

if (!function_exists('t8_contract_signature_is_valid')) {
    function t8_contract_signature_is_valid(array $signature, int $contractId, int $partyId, array $document): bool
    {
        $signedAt = (string) ($signature['signed_at'] ?? '');
        $signerName = trim((string) ($signature['signer_name'] ?? ''));
        $signerEmail = trim((string) ($signature['signer_email'] ?? ''));
        if ($signedAt === '' || $signerName === '' || $signerEmail === ''
            || (int) ($signature['signature_version_no'] ?? 0) !== (int) $document['version_no']
            || !hash_equals((string) $document['document_hash'], (string) ($signature['document_hash'] ?? ''))) {
            return false;
        }

        $expectedDigest = t8_contract_signature_digest(
            $contractId,
            $partyId,
            (string) $document['document_hash'],
            $signedAt,
            $signerName,
            $signerEmail
        );
        return hash_equals($expectedDigest, (string) ($signature['signature_hash'] ?? ''));
    }
}

if (!function_exists('t8_contract_all_parties_signed')) {
    function t8_contract_all_parties_signed(PDO $pdo, int $contractId): bool
    {
        if ($contractId <= 0) {
            return false;
        }

        t8_contract_signature_table_name($pdo);
        $document = t8_contract_frozen_document($pdo, $contractId);
        $partiesStmt = $pdo->prepare('SELECT party_id FROM team8_contract_parties WHERE contract_id = :contract_id');
        $partiesStmt->execute(['contract_id' => $contractId]);
        $partyIds = array_map('intval', $partiesStmt->fetchAll(PDO::FETCH_COLUMN));
        if ($partyIds === []) {
            return false;
        }

        $signatureStmt = $pdo->prepare(
            "SELECT signature_version_no, document_hash, signature_hash, signer_name, signer_email, signed_at, status
             FROM team8_contract_signatures
             WHERE contract_id = :contract_id AND party_id = :party_id AND voided_at IS NULL
               AND signature_version_no = :version_no
               AND LOWER(status) IN ('signed', 'complete', 'approved')
             ORDER BY id DESC LIMIT 1"
        );
        foreach ($partyIds as $partyId) {
            $signatureStmt->execute([
                'contract_id' => $contractId,
                'party_id' => $partyId,
                'version_no' => $document['version_no'],
            ]);
            $signature = $signatureStmt->fetch(PDO::FETCH_ASSOC);
            if (!$signature || !t8_contract_signature_is_valid($signature, $contractId, $partyId, $document)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('t8_contract_can_activate')) {
    function t8_contract_can_activate(PDO $pdo, int $contractId): bool
    {
        t8_contract_signature_table_name($pdo);
        $document = t8_contract_frozen_document($pdo, $contractId);
        $latestStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(version_no), 0) FROM team8_contract_history WHERE contract_id = :contract_id'
        );
        $latestStmt->execute(['contract_id' => $contractId]);
        if ((int) $latestStmt->fetchColumn() !== (int) $document['version_no']) {
            return false;
        }

        $negotiationStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM team8_contract_negotiations WHERE contract_id = :contract_id AND status = 'pending'"
        );
        $negotiationStmt->execute(['contract_id' => $contractId]);
        return (int) $negotiationStmt->fetchColumn() === 0;
    }
}

if (!function_exists('t8_contract_void_signatures_for_revision')) {
    function t8_contract_void_signatures_for_revision(PDO $pdo, int $contractId, string $reason = 'superseded by new version'): void
    {
        t8_contract_signature_table_name($pdo);
        $stmt = $pdo->prepare(
            'UPDATE team8_contract_signatures
             SET voided_at = CURRENT_TIMESTAMP, voided_reason = :reason
             WHERE contract_id = :contract_id AND voided_at IS NULL'
        );
        if (!$stmt->execute(['reason' => $reason, 'contract_id' => $contractId])) {
            throw new RuntimeException('Existing signatures could not be voided for revision.');
        }
        $cleared = $pdo->prepare('UPDATE team8_contracts SET signature_version_no = NULL, signature_document_hash = NULL WHERE id = :id')
            ->execute(['id' => $contractId]);
        if (!$cleared) {
            throw new RuntimeException('The frozen signature version could not be cleared.');
        }
    }
}

if (!function_exists('t8_contract_refresh_signature_status')) {
    function t8_contract_refresh_signature_status(PDO $pdo, int $contractId): string
    {
        if ($contractId <= 0) {
            return 'pending_signature';
        }

        $partiesStmt = $pdo->prepare('SELECT COUNT(*) FROM team8_contract_parties WHERE contract_id = :contract_id');
        $partiesStmt->execute(['contract_id' => $contractId]);
        $partyCount = (int) $partiesStmt->fetchColumn();
        if ($partyCount === 0) {
            return 'pending_signature';
        }

        $document = t8_contract_frozen_document($pdo, $contractId);
        if (t8_contract_all_parties_signed($pdo, $contractId)) {
            return 'fully_executed';
        }
        $signedStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM team8_contract_signatures
             WHERE contract_id = :contract_id AND voided_at IS NULL
               AND signature_version_no = :version_no
               AND LOWER(status) IN ('signed', 'complete', 'approved')"
        );
        $signedStmt->execute(['contract_id' => $contractId, 'version_no' => $document['version_no']]);
        $signedCount = (int) $signedStmt->fetchColumn();

        if ($signedCount === 0) {
            return 'pending_signature';
        }
        return 'partially_signed';
    }
}

if (!function_exists('t8_contract_register_signature')) {
    function t8_contract_register_signature(
        PDO $pdo,
        int $contractId,
        int $partyId,
        string $signerName,
        string $signerEmail,
        string $status = 'signed',
        ?string $signedAt = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): ?array {
        $signerName = trim($signerName);
        $signerEmail = trim($signerEmail);
        $ipAddress = $ipAddress ?? (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent = $userAgent ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $status = strtolower(trim($status));
        if ($contractId <= 0 || $partyId <= 0 || $signerName === '' || $signerEmail === '') {
            return null;
        }
        if (!in_array($status, ['pending', 'signed', 'complete', 'approved'], true)) {
            throw new InvalidArgumentException('Only pending or completed party signatures can be recorded.');
        }

        t8_contract_signature_table_name($pdo);
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $lockSql = 'SELECT status, signature_version_no FROM team8_contracts WHERE id = :id';
            if ($driver !== 'sqlite') {
                $lockSql .= ' FOR UPDATE';
            }
            $contractStmt = $pdo->prepare($lockSql);
            $contractStmt->execute(['id' => $contractId]);
            $contract = $contractStmt->fetch(PDO::FETCH_ASSOC);
            if (!$contract || $contract['status'] !== 'pending_signature') {
                throw new RuntimeException('Signatures can only be recorded while the contract is pending signature.');
            }

            $partyStmt = $pdo->prepare(
                'SELECT 1 FROM team8_contract_parties WHERE contract_id = :contract_id AND party_id = :party_id'
            );
            $partyStmt->execute(['contract_id' => $contractId, 'party_id' => $partyId]);
            if ($partyStmt->fetchColumn() === false) {
                throw new RuntimeException('The signer is not an involved party on this contract.');
            }
            $document = t8_contract_frozen_document($pdo, $contractId);
            $signedAtValue = in_array($status, ['signed', 'complete', 'approved'], true)
                ? ($signedAt ?: date('Y-m-d H:i:s'))
                : null;
            $signatureHash = $signedAtValue === null
                ? null
                : t8_contract_signature_digest($contractId, $partyId, $document['document_hash'], $signedAtValue, $signerName, $signerEmail);

            $existingStmt = $pdo->prepare(
                'SELECT id, status FROM team8_contract_signatures
                 WHERE contract_id = :contract_id AND party_id = :party_id
                   AND signature_version_no = :version_no AND voided_at IS NULL
                 ORDER BY id DESC LIMIT 1'
            );
            $existingStmt->execute([
                'contract_id' => $contractId,
                'party_id' => $partyId,
                'version_no' => $document['version_no'],
            ]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if ($existing && in_array(strtolower((string) $existing['status']), ['signed', 'complete', 'approved'], true)) {
                throw new RuntimeException('A live signature already exists for this party and frozen version.');
            }

            if ($existing) {
                $stmt = $pdo->prepare(
                    'UPDATE team8_contract_signatures SET signer_name = :signer_name, signer_email = :signer_email,
                     status = :status, signed_at = :signed_at, document_hash = :document_hash,
                     signature_hash = :signature_hash, ip_address = :ip_address, user_agent = :user_agent
                     WHERE id = :id AND voided_at IS NULL'
                );
                $params = [
                    'signer_name' => $signerName,
                    'signer_email' => $signerEmail,
                    'status' => $status,
                    'signed_at' => $signedAtValue,
                    'document_hash' => $document['document_hash'],
                    'signature_hash' => $signatureHash,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'id' => (int) $existing['id'],
                ];
                if (!$stmt->execute($params) || $stmt->rowCount() !== 1) {
                    throw new RuntimeException('The pending signature could not be updated.');
                }
                $signatureId = (int) $existing['id'];
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO team8_contract_signatures
                     (contract_id, party_id, signature_version_no, document_hash, signature_hash, signer_name,
                      signer_email, status, signed_at, ip_address, user_agent)
                     VALUES (:contract_id, :party_id, :version_no, :document_hash, :signature_hash, :signer_name,
                      :signer_email, :status, :signed_at, :ip_address, :user_agent)'
                );
                if (!$stmt->execute([
                    'contract_id' => $contractId,
                    'party_id' => $partyId,
                    'version_no' => $document['version_no'],
                    'document_hash' => $document['document_hash'],
                    'signature_hash' => $signatureHash,
                    'signer_name' => $signerName,
                    'signer_email' => $signerEmail,
                    'status' => $status,
                    'signed_at' => $signedAtValue,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                ])) {
                    throw new RuntimeException('The party signature could not be recorded.');
                }
                $signatureId = (int) $pdo->lastInsertId();
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
            return [
                'id' => $signatureId,
                'contract_id' => $contractId,
                'party_id' => $partyId,
                'signature_version_no' => $document['version_no'],
                'document_hash' => $document['document_hash'],
                'signature_hash' => $signatureHash,
                'signer_name' => $signerName,
                'signer_email' => $signerEmail,
                'status' => $status,
                'signed_at' => $signedAtValue,
            ];
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('t8_contract_record_signature')) {
    function t8_contract_record_signature(
        PDO $pdo,
        int $contractId,
        int $partyId,
        string $signerName,
        string $signerEmail,
        string $status = 'signed',
        ?string $signedAt = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): ?array {
        return t8_contract_register_signature(
            $pdo,
            $contractId,
            $partyId,
            $signerName,
            $signerEmail,
            $status,
            $signedAt,
            $ipAddress,
            $userAgent
        );
    }
}

if (!function_exists('t8_contract_update_signature_status')) {
    function t8_contract_update_signature_status(
        PDO $pdo,
        int $contractId,
        int $partyId,
        string $status,
        ?string $signedAt = null,
        ?string $signerName = null,
        ?string $signerEmail = null
    ): bool {
        $normalizedStatus = strtolower(trim($status));
        if (!in_array($normalizedStatus, ['pending', 'signed', 'complete', 'approved'], true)) {
            throw new InvalidArgumentException('Only pending or completed signature statuses are supported.');
        }

        if (in_array($normalizedStatus, ['signed', 'complete', 'approved'], true)) {
            $stmt = $pdo->prepare(
                'SELECT signer_name, signer_email FROM team8_contract_signatures
                 WHERE contract_id = :contract_id AND party_id = :party_id AND voided_at IS NULL
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(['contract_id' => $contractId, 'party_id' => $partyId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $signerName = $signerName ?: (string) $existing['signer_name'];
                $signerEmail = $signerEmail ?: (string) $existing['signer_email'];
            }
            return t8_contract_register_signature(
                $pdo,
                $contractId,
                $partyId,
                (string) $signerName,
                (string) $signerEmail,
                $normalizedStatus,
                $signedAt
            ) !== null;
        }

        t8_contract_signature_table_name($pdo);
        $stmt = $pdo->prepare(
            "UPDATE team8_contract_signatures SET status = 'pending', signed_at = NULL, signature_hash = NULL
             WHERE contract_id = :contract_id AND party_id = :party_id AND voided_at IS NULL
               AND signature_version_no = (SELECT signature_version_no FROM team8_contracts WHERE id = :freeze_contract_id)"
        );
        $stmt->execute(['contract_id' => $contractId, 'party_id' => $partyId, 'freeze_contract_id' => $contractId]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('t8_contract_mark_partially_signed')) {
    function t8_contract_mark_partially_signed(PDO $pdo, int $contractId): bool
    {
        return $contractId > 0 && t8_contract_refresh_signature_status($pdo, $contractId) === 'partially_signed';
    }
}

if (!function_exists('t8_contract_mark_fully_executed')) {
    function t8_contract_mark_fully_executed(PDO $pdo, int $contractId): bool
    {
        if ($contractId <= 0) {
            return false;
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $lockSql = 'SELECT status FROM team8_contracts WHERE id = :id';
            if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                $lockSql .= ' FOR UPDATE';
            }
            $contractStmt = $pdo->prepare($lockSql);
            $contractStmt->execute(['id' => $contractId]);
            if (!in_array($contractStmt->fetchColumn(), ['pending_signature', 'partially_signed'], true)
                || !t8_contract_all_parties_signed($pdo, $contractId)) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return false;
            }

            $stmt = $pdo->prepare(
                "UPDATE team8_contracts SET status = 'fully_executed'
                 WHERE id = :id AND status IN ('pending_signature', 'partially_signed')"
            );
            $stmt->execute(['id' => $contractId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Contract status changed before execution could be recorded.');
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('t8_contract_mark_pending_signature')) {
    function t8_contract_mark_pending_signature(PDO $pdo, int $contractId): bool
    {
        t8_contract_freeze_signature_version($pdo, $contractId);
        return true;
    }
}
