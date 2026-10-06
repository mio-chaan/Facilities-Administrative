<?php
/** Contract signing workflow helpers. */
declare(strict_types=1);

if (!function_exists('t8_contract_signature_table_name')) {
    function t8_contract_signature_table_name(PDO $pdo): string
    {
        $tableNames = ['team8_contract_signatures', 'team8_contract_signers'];
        foreach ($tableNames as $tableName) {
            try {
                $pdo->query('SELECT 1 FROM ' . $tableName . ' LIMIT 1');
                return $tableName;
            } catch (Throwable $e) {
                // The table does not exist in the current schema yet.
            }
        }

        return 'team8_contract_signatures';
    }
}

if (!function_exists('t8_contract_ensure_signature_table')) {
    function t8_contract_ensure_signature_table(PDO $pdo): string
    {
        $tableName = t8_contract_signature_table_name($pdo);
        if ($tableName !== 'team8_contract_signatures' && $tableName !== 'team8_contract_signers') {
            $tableName = 'team8_contract_signatures';
        }

        if ($tableName === 'team8_contract_signers') {
            return $tableName;
        }

        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS team8_contract_signatures (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    contract_id INTEGER NOT NULL,
                    party_id INTEGER DEFAULT NULL,
                    signer_name TEXT NOT NULL,
                    signer_email TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'pending',
                    signed_at TEXT DEFAULT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                )"
            );
            return 'team8_contract_signatures';
        } catch (Throwable $e) {
            try {
                $pdo->exec(
                    "CREATE TABLE IF NOT EXISTS team8_contract_signers (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        contract_id INTEGER NOT NULL,
                        party_id INTEGER DEFAULT NULL,
                        signer_name TEXT NOT NULL,
                        signer_email TEXT NOT NULL,
                        status TEXT NOT NULL DEFAULT 'pending',
                        signed_at TEXT DEFAULT NULL,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )"
                );
                return 'team8_contract_signers';
            } catch (Throwable $e2) {
                return 'team8_contract_signatures';
            }
        }
    }
}

if (!function_exists('t8_contract_refresh_signature_status')) {
    function t8_contract_refresh_signature_status(PDO $pdo, int $contractId): string
    {
        if ($contractId <= 0) {
            return 'pending_signature';
        }

        $tableName = t8_contract_ensure_signature_table($pdo);
        $status = 'pending_signature';

        try {
            $requiredCount = 0;
            $partyStmt = $pdo->prepare('SELECT COUNT(*) FROM team8_contract_parties WHERE contract_id = :contract_id');
            $partyStmt->execute(['contract_id' => $contractId]);
            $requiredCount = (int) $partyStmt->fetchColumn();

            if ($requiredCount <= 0) {
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $tableName . ' WHERE contract_id = :contract_id');
                $countStmt->execute(['contract_id' => $contractId]);
                $requiredCount = (int) $countStmt->fetchColumn();
            }

            $signedStmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $tableName . ' WHERE contract_id = :contract_id AND LOWER(status) IN (\'signed\', \'complete\', \'approved\')');
            $signedStmt->execute(['contract_id' => $contractId]);
            $signedCount = (int) $signedStmt->fetchColumn();

            if ($requiredCount > 0 && $signedCount >= $requiredCount) {
                $status = 'fully_executed';
            } elseif ($signedCount > 0) {
                $status = 'partially_signed';
            } else {
                $status = 'pending_signature';
            }

            $contractStmt = $pdo->prepare('UPDATE team8_contracts SET status = :status WHERE id = :id');
            $contractStmt->execute([
                'status' => $status,
                'id' => $contractId,
            ]);

            return $status;
        } catch (Throwable $e) {
            return 'pending_signature';
        }
    }
}

if (!function_exists('t8_contract_register_signature')) {
    function t8_contract_register_signature(PDO $pdo, int $contractId, int $partyId, string $signerName, string $signerEmail, string $status = 'pending', ?string $signedAt = null): ?array
    {
        $contractId = (int) $contractId;
        $partyId = (int) $partyId;
        $signerName = trim((string) $signerName);
        $signerEmail = trim((string) $signerEmail);
        $status = strtolower(trim($status));

        if ($contractId <= 0 || $signerName === '' || $signerEmail === '') {
            return null;
        }

        $tableName = t8_contract_ensure_signature_table($pdo);
        $resolvedStatus = in_array($status, ['pending', 'signed', 'complete', 'approved', 'rejected', 'declined'], true) ? $status : 'pending';
        $signedAtValue = $signedAt ?: (($resolvedStatus === 'signed' || $resolvedStatus === 'complete' || $resolvedStatus === 'approved') ? date('Y-m-d H:i:s') : null);

        try {
            $existing = $pdo->prepare('SELECT id FROM ' . $tableName . ' WHERE contract_id = :contract_id AND ((party_id = :party_id AND :party_id > 0) OR LOWER(signer_email) = LOWER(:signer_email)) LIMIT 1');
            $existing->execute([
                'contract_id' => $contractId,
                'party_id' => $partyId,
                'signer_email' => $signerEmail,
            ]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);

            if ($row !== false && isset($row['id'])) {
                $recordId = (int) $row['id'];
                $stmt = $pdo->prepare(
                    'UPDATE ' . $tableName . ' SET signer_name = :signer_name, signer_email = :signer_email, party_id = :party_id, status = :status, signed_at = :signed_at WHERE id = :id'
                );
                $updated = $stmt->execute([
                    'signer_name' => $signerName,
                    'signer_email' => $signerEmail,
                    'party_id' => $partyId > 0 ? $partyId : null,
                    'status' => $resolvedStatus,
                    'signed_at' => $signedAtValue,
                    'id' => $recordId,
                ]);
                if (!$updated) {
                    return null;
                }
                $record = ['id' => $recordId, 'contract_id' => $contractId, 'party_id' => $partyId > 0 ? $partyId : null, 'signer_name' => $signerName, 'signer_email' => $signerEmail, 'status' => $resolvedStatus, 'signed_at' => $signedAtValue];
                t8_contract_refresh_signature_status($pdo, $contractId);
                return $record;
            }

            $insertStmt = $pdo->prepare(
                'INSERT INTO ' . $tableName . ' (contract_id, party_id, signer_name, signer_email, status, signed_at) VALUES (:contract_id, :party_id, :signer_name, :signer_email, :status, :signed_at)'
            );
            $result = $insertStmt->execute([
                'contract_id' => $contractId,
                'party_id' => $partyId > 0 ? $partyId : null,
                'signer_name' => $signerName,
                'signer_email' => $signerEmail,
                'status' => $resolvedStatus,
                'signed_at' => $signedAtValue,
            ]);

            if (!$result) {
                return null;
            }

            $record = [
                'id' => (int) $pdo->lastInsertId(),
                'contract_id' => $contractId,
                'party_id' => $partyId > 0 ? $partyId : null,
                'signer_name' => $signerName,
                'signer_email' => $signerEmail,
                'status' => $resolvedStatus,
                'signed_at' => $signedAtValue,
            ];

            t8_contract_refresh_signature_status($pdo, $contractId);
            return $record;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('t8_contract_record_signature')) {
    function t8_contract_record_signature(PDO $pdo, int $contractId, int $partyId, string $signerName, string $signerEmail, string $status = 'signed', ?string $signedAt = null): ?array
    {
        return t8_contract_register_signature($pdo, $contractId, $partyId, $signerName, $signerEmail, $status, $signedAt);
    }
}

if (!function_exists('t8_contract_update_signature_status')) {
    function t8_contract_update_signature_status(PDO $pdo, int $contractId, int $partyId, string $status, ?string $signedAt = null, ?string $signerName = null, ?string $signerEmail = null): bool
    {
        $contractId = (int) $contractId;
        $partyId = (int) $partyId;
        $status = strtolower(trim((string) $status));

        if ($contractId <= 0 || $partyId <= 0) {
            return false;
        }

        $tableName = t8_contract_ensure_signature_table($pdo);

        try {
            $normalizedStatus = in_array($status, ['pending', 'signed', 'complete', 'approved', 'rejected', 'declined'], true) ? $status : 'pending';
            $signedTimestamp = $signedAt ?: (($normalizedStatus === 'signed' || $normalizedStatus === 'complete' || $normalizedStatus === 'approved') ? date('Y-m-d H:i:s') : null);
            $updateStmt = $pdo->prepare(
                'UPDATE ' . $tableName . ' SET status = :status, signed_at = :signed_at WHERE contract_id = :contract_id AND party_id = :party_id'
            );
            $updated = $updateStmt->execute([
                'status' => $normalizedStatus,
                'signed_at' => $signedTimestamp,
                'contract_id' => $contractId,
                'party_id' => $partyId,
            ]);

            if (!$updated) {
                return false;
            }

            t8_contract_refresh_signature_status($pdo, $contractId);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('t8_contract_mark_partially_signed')) {
    function t8_contract_mark_partially_signed(PDO $pdo, int $contractId): bool
    {
        if ($contractId <= 0) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("UPDATE team8_contracts SET status = 'partially_signed' WHERE id = :id");
            $updated = $stmt->execute(['id' => $contractId]);
            return $updated && $stmt->rowCount() >= 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('t8_contract_mark_fully_executed')) {
    function t8_contract_mark_fully_executed(PDO $pdo, int $contractId): bool
    {
        if ($contractId <= 0) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("UPDATE team8_contracts SET status = 'fully_executed' WHERE id = :id");
            $updated = $stmt->execute(['id' => $contractId]);
            return $updated && $stmt->rowCount() >= 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('t8_contract_mark_pending_signature')) {
    function t8_contract_mark_pending_signature(PDO $pdo, int $contractId): bool
    {
        if ($contractId <= 0) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("UPDATE team8_contracts SET status = 'pending_signature' WHERE id = :id");
            $updated = $stmt->execute(['id' => $contractId]);
            return $updated && $stmt->rowCount() >= 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}
