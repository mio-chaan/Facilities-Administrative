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

        $historyVersionStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(version_no), 0) FROM team8_contract_history WHERE contract_id = :contract_id'
        );
        $historyVersionStmt->execute(['contract_id' => $contractId]);
        $negotiationVersionStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(version_no), 0) FROM team8_contract_negotiations WHERE contract_id = :contract_id'
        );
        $negotiationVersionStmt->execute(['contract_id' => $contractId]);
        $versionNo = max((int) $historyVersionStmt->fetchColumn(), (int) $negotiationVersionStmt->fetchColumn()) + 1;

        $versionData = $contract;
        unset($versionData['id'], $versionData['status'], $versionData['created_at'], $versionData['updated_at'], $versionData['deleted_at']);
        $updateData = [];
        foreach ($sanitizedData as $field => $value) {
            if ($field === 'amount' || $field === 'deposit_amount') {
                $normalizedValue = $value === '' || $value === null ? null : (float) $value;
            } elseif ($field === 'department_id' || $field === 'notice_period_days' || $field === 'renewed_from_id') {
                $normalizedValue = $value === '' || $value === null ? null : (int) $value;
            } else {
                $normalizedValue = $value === '' ? null : $value;
            }
            $versionData[$field] = $normalizedValue;
            $updateData[$field] = $normalizedValue;
        }

        $versionJson = json_encode($versionData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $setClause = implode(', ', array_map(
            static fn (string $field): string => $field . ' = :' . $field,
            array_keys($updateData)
        ));
        $updateData['contract_status'] = 'under_review';
        $updateData['updated_at'] = date('Y-m-d H:i:s');
        $updateData['id'] = $contractId;
        $updateStmt = $pdo->prepare(
            'UPDATE team8_contracts SET ' . $setClause . ', status = :contract_status, updated_at = :updated_at WHERE id = :id AND status IN (\'under_review\', \'negotiation\')'
        );
        $updateStmt->execute($updateData);
        if ($updateStmt->rowCount() !== 1) {
            throw new RuntimeException('Contract status changed while saving revised terms.');
        }

        $pdo->prepare(
            "UPDATE team8_contract_negotiations
             SET status = 'superseded', resolved_at = CURRENT_TIMESTAMP
             WHERE contract_id = :contract_id AND source = 'internal' AND status = 'pending'"
        )->execute(['contract_id' => $contractId]);
        $pdo->prepare(
            "UPDATE team8_contract_negotiations
             SET status = 'accepted', resolved_at = CURRENT_TIMESTAMP
             WHERE contract_id = :contract_id AND source = 'external' AND status = 'pending'"
        )->execute(['contract_id' => $contractId]);

        $historyStmt = $pdo->prepare('INSERT INTO team8_contract_history (contract_id, version_no, data_json, changed_by, created_at) VALUES (:contract_id, :version_no, :data_json, :changed_by, CURRENT_TIMESTAMP)');
        $historyStmt->execute([
            'contract_id' => $contractId,
            'version_no' => $versionNo,
            'data_json' => $versionJson,
            'changed_by' => $proposedBy,
        ]);

        $proposalStmt = $pdo->prepare('INSERT INTO team8_contract_negotiations (contract_id, version_no, proposed_by, change_summary, proposed_data, status, source, created_at, resolved_at) VALUES (:contract_id, :version_no, :proposed_by, :change_summary, :proposed_data, :status, :source, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $proposalStmt->execute([
            'contract_id' => $contractId,
            'version_no' => $versionNo,
            'proposed_by' => $proposedBy,
            'change_summary' => $summary,
            'proposed_data' => json_encode($sanitizedData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'status' => 'accepted',
            'source' => $source,
        ]);

        $pdo->commit();
        return [
            'id' => (int) $pdo->lastInsertId(),
            'contract_id' => $contractId,
            'version_no' => $versionNo,
            'proposed_by' => $proposedBy,
            'change_summary' => $summary,
            'status' => 'accepted',
            'source' => $source,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Contract revision could not be saved for contract #' . $contractId . ': ' . $e->getMessage());
        return null;
    }
}
