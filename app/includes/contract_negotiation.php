<?php
/** Contract negotiation versioning helpers. */
declare(strict_types=1);

function t8_contract_create_negotiation(
    PDO $pdo,
    int $contractId,
    int $proposedBy,
    string $changeSummary,
    array $proposedData,
    string $source = 'internal'
): ?array {
    $summary = trim($changeSummary);
    if ($contractId <= 0 || $proposedBy <= 0 || $summary === '') {
        return null;
    }

    $allowedFields = [
        'title', 'contract_type', 'description', 'department_id', 'start_date',
        'end_date', 'renewal_date', 'amount', 'currency', 'payment_terms',
        'payment_frequency', 'deposit_amount', 'financial_notes',
        'notice_period_days', 'renewed_from_id', 'attachment_path',
        'attachment_type', 'attachment_name',
    ];
    $sanitizedData = [];
    foreach ($proposedData as $field => $value) {
        if (!in_array((string) $field, $allowedFields, true)) {
            continue;
        }
        $sanitizedData[$field] = $value;
    }
    if ($sanitizedData === []) {
        return null;
    }

    $pdo->beginTransaction();
    try {
        $contractStmt = $pdo->prepare('SELECT * FROM team8_contracts WHERE id = :id');
        $contractStmt->execute(['id' => $contractId]);
        $contract = $contractStmt->fetch(PDO::FETCH_ASSOC);
        if ($contract === false) {
            throw new RuntimeException('Contract not found.');
        }
        if (!in_array((string) $contract['status'], ['under_review', 'negotiation'], true)) {
            throw new RuntimeException('Contract is not available for negotiation.');
        }
        $hasChanges = false;
        foreach ($sanitizedData as $field => $value) {
            if ((string) ($contract[$field] ?? '') !== trim((string) $value)) {
                $hasChanges = true;
                break;
            }
        }
        if (!$hasChanges) {
            throw new RuntimeException('At least one contract field must change.');
        }

        $versionStmt = $pdo->prepare(
            'SELECT GREATEST(
                (SELECT COALESCE(MAX(version_no), 0) FROM team8_contract_history WHERE contract_id = :history_contract_id),
                (SELECT COALESCE(MAX(version_no), 0) FROM team8_contract_negotiations WHERE contract_id = :negotiation_contract_id)
             ) + 1 AS next_version'
        );
        $versionStmt->execute([
            'history_contract_id' => $contractId,
            'negotiation_contract_id' => $contractId,
        ]);
        $versionNo = (int) $versionStmt->fetchColumn();

        $versionData = $contract;
        unset($versionData['id'], $versionData['status'], $versionData['created_at'], $versionData['updated_at'], $versionData['deleted_at']);
        foreach ($sanitizedData as $field => $value) {
            if ($field === 'amount' || $field === 'deposit_amount') {
                $versionData[$field] = $value === '' || $value === null ? null : (float) $value;
            } elseif ($field === 'department_id' || $field === 'notice_period_days' || $field === 'renewed_from_id') {
                $versionData[$field] = $value === '' || $value === null ? null : (int) $value;
            } else {
                $versionData[$field] = $value === '' ? null : $value;
            }
        }

        $versionJson = json_encode($versionData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $historyStmt = $pdo->prepare('INSERT INTO team8_contract_history (contract_id, version_no, data_json, changed_by, created_at) VALUES (:contract_id, :version_no, :data_json, :changed_by, CURRENT_TIMESTAMP)');
        $historyStmt->execute([
            'contract_id' => $contractId,
            'version_no' => $versionNo,
            'data_json' => $versionJson,
            'changed_by' => $proposedBy,
        ]);

        $proposalStmt = $pdo->prepare('INSERT INTO team8_contract_negotiations (contract_id, version_no, proposed_by, change_summary, proposed_data, status, source, created_at) VALUES (:contract_id, :version_no, :proposed_by, :change_summary, :proposed_data, :status, :source, CURRENT_TIMESTAMP)');
        $proposalStmt->execute([
            'contract_id' => $contractId,
            'version_no' => $versionNo,
            'proposed_by' => $proposedBy,
            'change_summary' => $summary,
            'proposed_data' => json_encode($sanitizedData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'source' => $source,
        ]);

        $pdo->commit();
        return [
            'id' => (int) $pdo->lastInsertId(),
            'contract_id' => $contractId,
            'version_no' => $versionNo,
            'proposed_by' => $proposedBy,
            'change_summary' => $summary,
            'status' => 'pending',
            'source' => $source,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return null;
    }
}

function t8_contract_accept_negotiation(PDO $pdo, int $contractId, int $proposalId, int $approvedBy): bool
{
    if ($contractId <= 0 || $proposalId <= 0 || $approvedBy <= 0) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $proposalStmt = $pdo->prepare("SELECT * FROM team8_contract_negotiations WHERE id = :id AND contract_id = :contract_id AND status = :status AND source = 'internal' AND proposed_by IS NOT NULL");
        $proposalStmt->execute([
            'id' => $proposalId,
            'contract_id' => $contractId,
            'status' => 'pending',
        ]);
        $proposal = $proposalStmt->fetch(PDO::FETCH_ASSOC);
        if ($proposal === false) {
            throw new RuntimeException('Pending negotiation proposal not found.');
        }

        $proposedData = json_decode((string) $proposal['proposed_data'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($proposedData)) {
            throw new RuntimeException('Proposal data is invalid.');
        }
        $updateFields = array_keys($proposedData);
        if ($updateFields === []) {
            throw new RuntimeException('Proposal contains no fields.');
        }
        $setClause = implode(', ', array_map(static fn (string $field): string => $field . ' = :' . $field, $updateFields));
        $updateParams = $proposedData;
        $updateParams['id'] = $contractId;
        $updateParams['status'] = 'under_review';
        $updateParams['updated_at'] = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE team8_contracts SET ' . $setClause . ', status = :status, updated_at = :updated_at WHERE id = :id')->execute($updateParams);

        $pdo->prepare('UPDATE team8_contract_negotiations SET status = :status, resolved_at = CURRENT_TIMESTAMP WHERE id = :id AND status = :pending')->execute([
            'status' => 'accepted',
            'id' => $proposalId,
            'pending' => 'pending',
        ]);
        $pdo->prepare(
            "UPDATE team8_contract_negotiations
             SET status = 'accepted', resolved_at = CURRENT_TIMESTAMP
             WHERE contract_id = :contract_id AND source = 'external' AND status = 'pending'"
        )->execute(['contract_id' => $contractId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}
