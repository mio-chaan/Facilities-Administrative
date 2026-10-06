<?php
declare(strict_types=1);

if (!function_exists('t8_contract_create_renewal')) {
    function t8_contract_create_renewal(PDO $pdo, array $contract, int $actorId): ?array
    {
        if (!isset($contract['id'])) {
            return null;
        }

        if (t8_contract_normalize_status((string) ($contract['status'] ?? '')) !== 'active') {
            return null;
        }

        $originalId = (int) $contract['id'];

        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }

            $baseTitle = trim((string) ($contract['title'] ?? ''));
            $renewalTitle = $baseTitle === '' ? 'Renewal Contract' : $baseTitle . ' (Renewal)';

            $row = [
                'contract_number' => t8_contract_next_number($pdo),
                'owner_id' => (int) ($contract['owner_id'] ?? $actorId),
                'department_id' => isset($contract['department_id']) && (string) $contract['department_id'] !== '' ? (int) $contract['department_id'] : null,
                'renewed_from_id' => $originalId,
                'title' => $renewalTitle,
                'contract_type' => (string) ($contract['contract_type'] ?? ''),
                'description' => (string) ($contract['description'] ?? ''),
                'start_date' => date('Y-m-d'),
                'end_date' => isset($contract['end_date']) && (string) $contract['end_date'] !== '' ? (string) $contract['end_date'] : null,
                'renewal_date' => isset($contract['renewal_date']) && (string) $contract['renewal_date'] !== '' ? (string) $contract['renewal_date'] : null,
                'amount' => $contract['amount'] ?? null,
                'currency' => (string) ($contract['currency'] ?? 'PHP'),
                'payment_terms' => $contract['payment_terms'] ?? null,
                'payment_frequency' => $contract['payment_frequency'] ?? null,
                'deposit_amount' => $contract['deposit_amount'] ?? null,
                'financial_notes' => $contract['financial_notes'] ?? null,
                'notice_period_days' => isset($contract['notice_period_days']) && (string) $contract['notice_period_days'] !== '' ? (int) $contract['notice_period_days'] : null,
                'termination_date' => null,
                'termination_reason' => null,
                'rejection_reason' => null,
                'attachment_path' => isset($contract['attachment_path']) && trim((string) $contract['attachment_path']) !== '' ? (string) $contract['attachment_path'] : null,
                'attachment_type' => isset($contract['attachment_type']) && trim((string) $contract['attachment_type']) !== '' ? (string) $contract['attachment_type'] : null,
                'attachment_name' => isset($contract['attachment_name']) && trim((string) $contract['attachment_name']) !== '' ? (string) $contract['attachment_name'] : null,
                'status' => 'draft',
            ];

            $columns = array_keys($row);
            $placeholders = implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns));
            $columnList = implode(', ', $columns);

            $stmt = $pdo->prepare('INSERT INTO team8_contracts (' . $columnList . ') VALUES (' . $placeholders . ')');
            $stmt->execute($row);

            $newId = (int) $pdo->lastInsertId();
            if ($newId <= 0) {
                throw new RuntimeException('Renewal draft insert failed.');
            }

            $partyRows = $pdo->prepare('SELECT party_id, role_in_contract FROM team8_contract_parties WHERE contract_id = :contract_id');
            $partyRows->execute(['contract_id' => $originalId]);

            $copyParty = $pdo->prepare(
                'INSERT INTO team8_contract_parties (contract_id, party_id, role_in_contract)
                 VALUES (:contract_id, :party_id, :role_in_contract)'
            );

            foreach ($partyRows->fetchAll(PDO::FETCH_ASSOC) as $party) {
                $copyParty->execute([
                    'contract_id' => $newId,
                    'party_id' => (int) $party['party_id'],
                    'role_in_contract' => (string) ($party['role_in_contract'] ?? 'Counterparty'),
                ]);
            }

            $originalUpdate = $pdo->prepare('UPDATE team8_contracts SET status = :status WHERE id = :id AND status = :expected_status');
            $updated = $originalUpdate->execute([
                'status' => 'renewal',
                'id' => $originalId,
                'expected_status' => 'active',
            ]);

            if (!$updated || $originalUpdate->rowCount() !== 1) {
                throw new RuntimeException('Original contract status could not be marked for renewal.');
            }

            if (function_exists('t8_contract_event_log')) {
                t8_contract_event_log(
                    $pdo,
                    $originalId,
                    $actorId,
                    'renewal_initiated',
                    'Active contract moved into renewal and a renewal draft was created.',
                    ['new_contract_id' => $newId]
                );
                t8_contract_event_log(
                    $pdo,
                    $newId,
                    $actorId,
                    'renewal_created',
                    'Renewal draft created from the original contract.',
                    ['renewed_from_id' => $originalId]
                );
            }

            if (function_exists('t8_audit_log')) {
                t8_audit_log($pdo, $actorId, 'contract', $originalId, 'renewal', 'active', 'renewal');
                t8_audit_log($pdo, $actorId, 'contract', $newId, 'renewal_created', 'draft', 'draft');
            }

            if (function_exists('t8_notify_user')) {
                $ownerId = (int) ($contract['owner_id'] ?? $actorId);
                if ($ownerId > 0) {
                    t8_notify_user(
                        $pdo,
                        $ownerId,
                        'A renewal draft has been created for ' . (string) ($contract['title'] ?? 'this contract') . '.',
                        'index.php?page=contracts&action=view&id=' . $newId
                    );
                }
            }

            $pdo->commit();

            return function_exists('t8_contract_fetch') ? t8_contract_fetch($pdo, $newId) : null;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return null;
        }
    }
}

if (!function_exists('t8_contract_mark_expired')) {
    function t8_contract_mark_expired(PDO $pdo, int $contractId, int $actorId, string $reason = '', ?string $expirationDate = null): bool
    {
        $date = $expirationDate ?: date('Y-m-d');
        $message = trim($reason) !== '' ? $reason : 'Contract expiration date was reached.';

        try {
            $stmt = $pdo->prepare(
                "UPDATE team8_contracts
                 SET status = 'expiration'
                 WHERE id = :id AND status IN ('active', 'renewal')"
            );
            $updated = $stmt->execute([
                'id' => $contractId,
            ]);

            if (!$updated || $stmt->rowCount() < 1) {
                return false;
            }

            if (function_exists('t8_contract_event_log')) {
                t8_contract_event_log($pdo, $contractId, $actorId, 'CONTRACT_EXPIRED', $message, ['expiration_date' => $date]);
            }
            if (function_exists('t8_audit_log')) {
                t8_audit_log($pdo, $actorId, 'contract', $contractId, 'expire', 'active', 'expiration');
            }
            if (function_exists('t8_notify_user')) {
                t8_notify_user($pdo, $actorId, 'Contract #' . (string) $contractId . ' has expired.', 'index.php?page=contracts&action=view&id=' . $contractId);
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('t8_contract_mark_terminated')) {
    function t8_contract_mark_terminated(PDO $pdo, int $contractId, int $actorId, string $reason = '', ?string $terminationDate = null): bool
    {
        $date = $terminationDate ?: date('Y-m-d');
        $message = trim($reason) !== '' ? $reason : 'Contract was terminated.';

        try {
            $stmt = $pdo->prepare(
                "UPDATE team8_contracts
                 SET status = 'termination', termination_date = :termination_date, termination_reason = :termination_reason, terminated_by = :terminated_by
                 WHERE id = :id AND status IN ('active', 'renewal', 'pending_approval', 'pending_signature', 'fully_executed')"
            );
            $updated = $stmt->execute([
                'termination_date' => $date,
                'termination_reason' => $message,
                'terminated_by' => $actorId,
                'id' => $contractId,
            ]);

            if (!$updated || $stmt->rowCount() < 1) {
                return false;
            }

            if (function_exists('t8_contract_event_log')) {
                t8_contract_event_log($pdo, $contractId, $actorId, 'CONTRACT_TERMINATED', $message, ['termination_date' => $date, 'terminated_by' => $actorId]);
            }
            if (function_exists('t8_audit_log')) {
                t8_audit_log($pdo, $actorId, 'contract', $contractId, 'terminate', 'active', 'termination');
            }
            if (function_exists('t8_notify_user')) {
                $notification = 'Contract termination has been recorded for ' . (string) $contractId . '.';
                t8_notify_user($pdo, $actorId, $notification, 'index.php?page=contracts&action=view&id=' . $contractId);
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('t8_contract_cancel')) {
    function t8_contract_cancel(PDO $pdo, int $contractId, int $actorId, string $reason = ''): bool
    {
        $message = trim($reason) !== '' ? $reason : 'Contract was cancelled before execution.';

        try {
            $stmt = $pdo->prepare(
                "UPDATE team8_contracts
                 SET status = 'cancelled', rejection_reason = :reason
                 WHERE id = :id AND status IN ('draft', 'under_review', 'negotiation', 'pending_approval', 'pending_signature')"
            );
            $updated = $stmt->execute([
                'reason' => $message,
                'id' => $contractId,
            ]);

            if (!$updated || $stmt->rowCount() < 1) {
                return false;
            }

            if (function_exists('t8_contract_event_log')) {
                t8_contract_event_log($pdo, $contractId, $actorId, 'CONTRACT_CANCELLED', $message, ['status' => 'cancelled']);
            }
            if (function_exists('t8_audit_log')) {
                t8_audit_log($pdo, $actorId, 'contract', $contractId, 'cancel', 'active', 'cancelled');
            }
            if (function_exists('t8_notify_user')) {
                t8_notify_user($pdo, $actorId, $message, 'index.php?page=contracts&action=view&id=' . $contractId);
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('t8_contract_mark_superseded')) {
    function t8_contract_mark_superseded(PDO $pdo, int $contractId, int $actorId, string $reason = ''): bool
    {
        $message = trim($reason) !== '' ? $reason : 'Contract was superseded by a newer version.';

        try {
            $stmt = $pdo->prepare(
                "UPDATE team8_contracts
                 SET status = 'superseded', rejection_reason = :reason
                 WHERE id = :id AND status IN ('active', 'renewal', 'expiration', 'termination')"
            );
            $updated = $stmt->execute([
                'reason' => $message,
                'id' => $contractId,
            ]);

            if (!$updated || $stmt->rowCount() < 1) {
                return false;
            }

            if (function_exists('t8_contract_event_log')) {
                t8_contract_event_log($pdo, $contractId, $actorId, 'CONTRACT_SUPERSEDED', $message, ['status' => 'superseded']);
            }
            if (function_exists('t8_audit_log')) {
                t8_audit_log($pdo, $actorId, 'contract', $contractId, 'supersede', 'active', 'superseded');
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('t8_contract_mark_archived')) {
    function t8_contract_mark_archived(PDO $pdo, int $contractId, int $actorId, string $reason = ''): bool
    {
        $message = trim($reason) !== '' ? $reason : 'Contract archived for historical retention.';

        try {
            $stmt = $pdo->prepare(
                "UPDATE team8_contracts
                 SET status = 'archived', rejection_reason = :reason, deleted_at = NULL
                 WHERE id = :id AND status IN ('expiration', 'termination', 'cancelled', 'superseded')"
            );
            $updated = $stmt->execute([
                'reason' => $message,
                'id' => $contractId,
            ]);

            if (!$updated || $stmt->rowCount() < 1) {
                return false;
            }

            if (function_exists('t8_contract_event_log')) {
                t8_contract_event_log($pdo, $contractId, $actorId, 'CONTRACT_ARCHIVED', $message, ['status' => 'archived']);
            }
            if (function_exists('t8_audit_log')) {
                t8_audit_log($pdo, $actorId, 'contract', $contractId, 'archive', 'active', 'archived');
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}