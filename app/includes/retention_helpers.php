<?php
/**
 * app/includes/retention_helpers.php
 *
 * PHASE 1 of the Document/Legal/Contract/Retention rebuild.
 *
 * Shared helpers for the polymorphic team8_records table (see
 * database/migrations/2026_09_11_retention_polymorphic_rebuild.sql).
 * A retention record's entity_type/entity_id pair points at exactly
 * one of:
 *   'document'    -> team8_documents.id
 *   'contract'    -> team8_contracts.id
 *   'legal_case'  -> team8_legal_cases.id
 *
 * DISPOSAL CONTRACT (see docs/retention plan - Phase 1 decisions):
 *   - Document:   single-step disposal. Any admin disposes directly
 *                 via t8_retention_dispose_document(). No requester/
 *                 authorizer split.
 *   - Contract:   two-step. t8_retention_request_disposal() records
 *                 the request, t8_retention_authorize_disposal()
 *                 completes it. Requester and authorizer may be the
 *                 SAME admin (single admin-only control is enough for
 *                 a Contract per the confirmed plan).
 *   - Legal Case: two-step, but DUAL CONTROL is enforced in code -
 *                 t8_retention_authorize_disposal() rejects the
 *                 authorization if disposal_authorized_by would equal
 *                 disposal_requested_by. This is checked here, not
 *                 only in the UI, so a direct POST cannot bypass it.
 *
 * Requires $pdo (see db_connect.php) and auth_check.php's
 * t8_current_user_id() to already be loaded, same convention as
 * app/includes/hr_documents.php.
 */

declare(strict_types=1);

const T8_RETENTION_ENTITY_TYPES = ['document', 'contract', 'legal_case'];

const T8_RETENTION_STATUSES = ['active', 'due_review', 'archived', 'pending_disposal', 'disposed'];

// Suggested defaults surfaced in the "New Retention Record" form -
// admin can freely override both the basis text and the years, per
// the plan's "admin-configurable, not hardcoded law" decision.
const T8_RETENTION_SUGGESTED_BASES = [
    'document' => [
        ['basis' => 'BIR RR No. 7-2024 (EOPT Act) - financial/tax-relevant document', 'years' => 5],
        ['basis' => 'Labor Code / DOLE - employment record', 'years' => 3],
        ['basis' => 'Internal policy - general administrative document', 'years' => 5],
    ],
    'contract' => [
        ['basis' => 'Civil Code Art. 1144 - written contract (10-year prescriptive period)', 'years' => 10],
    ],
    'legal_case' => [
        ['basis' => 'General civil prescription period (Civil Code Arts. 1139-1155) + records practice', 'years' => 10],
    ],
];

if (!function_exists('t8_retention_disposal_rule')) {
    /**
     * Returns the disposal workflow shape for an entity type:
     *   dual_control      -> requester and authorizer must differ
     *   two_step          -> needs a separate request + authorize action at all
     *   archive_only_default -> UI should default to "Archive", not "Dispose", to
     *                           discourage skipping straight to disposal
     */
    function t8_retention_disposal_rule(string $entityType): array
    {
        return match ($entityType) {
            'document'   => ['two_step' => false, 'dual_control' => false, 'archive_only_default' => false],
            'contract'   => ['two_step' => true, 'dual_control' => false, 'archive_only_default' => true],
            'legal_case' => ['two_step' => true, 'dual_control' => true, 'archive_only_default' => true],
            default      => throw new InvalidArgumentException("Unknown retention entity type: {$entityType}"),
        };
    }
}

if (!function_exists('t8_retention_status_badge')) {
    function t8_retention_status_badge(string $status): string
    {
        $map = [
            'active'           => 't8-badge-approved',
            'due_review'       => 't8-badge-pending',
            'archived'         => 't8-badge-archived',
            'pending_disposal' => 't8-badge-pending',
            'disposed'         => 't8-badge-rejected',
        ];
        return $map[$status] ?? 't8-badge-pending';
    }
}

if (!function_exists('t8_retention_compute_disposition')) {
    function t8_retention_compute_disposition(string $startDate, int $years): string
    {
        $ts = strtotime($startDate);
        if ($ts === false) {
            throw new InvalidArgumentException("Invalid retention_start_date: {$startDate}");
        }
        return date('Y-m-d', strtotime("+{$years} years", $ts));
    }
}

if (!function_exists('t8_retention_resolve_entity')) {
    /**
     * Resolves display info for the polymorphic target, regardless of
     * type - title, owning/custodian name, current lifecycle status,
     * and the URL to view it in its own module. Returns null if the
     * underlying row no longer exists (e.g. hard-deleted elsewhere).
     */
    function t8_retention_resolve_entity(PDO $pdo, string $entityType, int $entityId): ?array
    {
        switch ($entityType) {
            case 'document':
                $stmt = $pdo->prepare(
                    'SELECT d.id, d.title AS label, d.status, u.full_name AS related_name
                     FROM team8_documents d
                     JOIN users u ON u.id = d.uploaded_by
                     WHERE d.id = :id LIMIT 1'
                );
                $stmt->execute(['id' => $entityId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    return null;
                }
                return [
                    'label'        => (string) $row['label'],
                    'status'       => (string) $row['status'],
                    'related_name' => (string) $row['related_name'],
                    'related_role' => 'Uploaded by',
                    'view_action'  => ['page' => 'documents', 'action' => 'versions', 'id' => $entityId],
                ];

            case 'contract':
                $stmt = $pdo->prepare(
                    'SELECT c.id, c.title AS label, c.status, c.end_date, c.termination_date, u.full_name AS related_name
                     FROM team8_contracts c
                     JOIN users u ON u.id = c.owner_id
                     WHERE c.id = :id LIMIT 1'
                );
                $stmt->execute(['id' => $entityId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    return null;
                }
                return [
                    'label'        => (string) $row['label'],
                    'status'       => (string) $row['status'],
                    'related_name' => (string) $row['related_name'],
                    'related_role' => 'Owner',
                    // CONFIRMED RULE: an early termination supersedes the
                    // originally scheduled end date for retention purposes.
                    'clock_reference_date' => (string) ($row['termination_date'] ?: $row['end_date']),
                    'view_action'  => ['page' => 'contracts', 'action' => 'edit', 'id' => $entityId],
                ];

            case 'legal_case':
                $stmt = $pdo->prepare(
                    'SELECT lc.id, lc.title AS label, lc.status, lc.deadline, u.full_name AS related_name
                     FROM team8_legal_cases lc
                     JOIN users u ON u.id = lc.assigned_to
                     WHERE lc.id = :id LIMIT 1'
                );
                $stmt->execute(['id' => $entityId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    return null;
                }
                return [
                    'label'        => (string) $row['label'],
                    'status'       => (string) $row['status'],
                    'related_name' => (string) $row['related_name'],
                    'related_role' => 'Assigned to',
                    'clock_reference_date' => null, // legal_case retention clock starts at closure, set explicitly at registration time
                    'view_action'  => ['page' => 'legal', 'action' => 'edit', 'id' => $entityId],
                ];

            default:
                throw new InvalidArgumentException("Unknown retention entity type: {$entityType}");
        }
    }
}

if (!function_exists('t8_retention_fetch')) {
    /** Fetch a retention row by its own id, with resolved entity display fields attached. */
    function t8_retention_fetch(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT r.*, u.full_name AS custodian_name,
                    req.full_name AS disposal_requested_by_name,
                    auth.full_name AS disposal_authorized_by_name
             FROM team8_records r
             JOIN users u ON u.id = r.custodian_id
             LEFT JOIN users req  ON req.id  = r.disposal_requested_by
             LEFT JOIN users auth ON auth.id = r.disposal_authorized_by
             WHERE r.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $entity = t8_retention_resolve_entity($pdo, (string) $row['entity_type'], (int) $row['entity_id']);
        $row['entity'] = $entity; // null if the underlying record no longer exists

        return $row;
    }
}

if (!function_exists('t8_retention_fetch_for_entity')) {
    /** Look up the retention row (if any) already registered for a given business record. */
    function t8_retention_fetch_for_entity(PDO $pdo, string $entityType, int $entityId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM team8_records WHERE entity_type = :type AND entity_id = :id LIMIT 1'
        );
        $stmt->execute(['type' => $entityType, 'id' => $entityId]);
        $foundId = $stmt->fetchColumn();
        return $foundId ? t8_retention_fetch($pdo, (int) $foundId) : null;
    }
}

if (!function_exists('t8_retention_register')) {
    /**
     * Registers (or re-registers) a business record under retention.
     * Upserts on the (entity_type, entity_id) unique key, so calling
     * this again for the same record updates its schedule rather than
     * creating a duplicate row.
     *
     * $startDate is the date the retention clock begins - the CALLER
     * decides this per entity type (e.g. Contract module passes
     * COALESCE(termination_date, end_date); Legal module passes the
     * case's closed/resolved date; Document module passes the upload
     * or filing-relevant date). This file does not guess it, since
     * only the calling module knows which date is authoritative at
     * the moment of registration.
     */
    function t8_retention_register(
        PDO $pdo,
        string $entityType,
        int $entityId,
        string $retentionBasis,
        int $retentionYears,
        string $startDate,
        int $custodianId,
        ?int $scheduleId = null
    ): int {
        if (!in_array($entityType, T8_RETENTION_ENTITY_TYPES, true)) {
            throw new InvalidArgumentException("Unknown retention entity type: {$entityType}");
        }

        $dispositionDate = t8_retention_compute_disposition($startDate, $retentionYears);

        $existing = $pdo->prepare('SELECT id FROM team8_records WHERE entity_type = :type AND entity_id = :id LIMIT 1');
        $existing->execute(['type' => $entityType, 'id' => $entityId]);
        $existingId = $existing->fetchColumn();

        if ($existingId) {
            $pdo->prepare(
                'UPDATE team8_records SET
                    schedule_id = :schedule_id, retention_basis = :basis, retention_years = :years,
                    retention_start_date = :start_date, disposition_date = :disposition_date,
                    custodian_id = :custodian_id
                 WHERE id = :id'
            )->execute([
                'schedule_id'      => $scheduleId,
                'basis'            => $retentionBasis,
                'years'            => $retentionYears,
                'start_date'       => $startDate,
                'disposition_date' => $dispositionDate,
                'custodian_id'     => $custodianId,
                'id'               => $existingId,
            ]);
            return (int) $existingId;
        }

        $pdo->prepare(
            'INSERT INTO team8_records
                (entity_type, entity_id, schedule_id, retention_basis, retention_years, retention_start_date, disposition_date, custodian_id, status)
             VALUES
                (:entity_type, :entity_id, :schedule_id, :basis, :years, :start_date, :disposition_date, :custodian_id, "active")'
        )->execute([
            'entity_type'      => $entityType,
            'entity_id'        => $entityId,
            'schedule_id'      => $scheduleId,
            'basis'            => $retentionBasis,
            'years'            => $retentionYears,
            'start_date'       => $startDate,
            'disposition_date' => $dispositionDate,
            'custodian_id'     => $custodianId,
        ]);
        return (int) $pdo->lastInsertId();
    }
}

if (!function_exists('t8_retention_archive')) {
    /** Archive step - identical shape for all three entity types. */
    function t8_retention_archive(PDO $pdo, int $recordId, string $reason): bool
    {
        $stmt = $pdo->prepare(
            "UPDATE team8_records SET status = 'archived', archived_at = NOW(), archive_reason = :reason
             WHERE id = :id AND status IN ('active', 'due_review')"
        );
        $stmt->execute(['reason' => $reason, 'id' => $recordId]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('t8_retention_dispose_document')) {
    /**
     * Single-step disposal for entity_type = 'document'. Refuses to
     * run against any other entity type, since Contracts and Legal
     * Cases must go through the two-step request/authorize pair below.
     */
    function t8_retention_dispose_document(PDO $pdo, int $recordId, int $adminId, string $reason): bool
    {
        $record = t8_retention_fetch($pdo, $recordId);
        if (!$record || $record['entity_type'] !== 'document' || $record['status'] !== 'archived') {
            return false;
        }

        $stmt = $pdo->prepare(
            "UPDATE team8_records SET status = 'disposed', disposed_at = NOW(), disposal_reason = :reason
             WHERE id = :id AND status = 'archived'"
        );
        $stmt->execute(['reason' => $reason, 'id' => $recordId]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('t8_retention_request_disposal')) {
    /**
     * Step 1 of 2 for Contracts and Legal Cases. Records who is
     * asking and why; does not dispose anything yet.
     */
    function t8_retention_request_disposal(PDO $pdo, int $recordId, int $requestedBy, string $reason): bool
    {
        $record = t8_retention_fetch($pdo, $recordId);
        if (!$record || $record['entity_type'] === 'document' || $record['status'] !== 'archived') {
            return false;
        }

        $stmt = $pdo->prepare(
            "UPDATE team8_records SET status = 'pending_disposal',
                disposal_requested_by = :requested_by, disposal_requested_at = NOW(), disposal_reason = :reason
             WHERE id = :id AND status = 'archived'"
        );
        $stmt->execute(['requested_by' => $requestedBy, 'reason' => $reason, 'id' => $recordId]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('t8_retention_authorize_disposal')) {
    /**
     * Step 2 of 2. Completes disposal for Contracts and Legal Cases.
     *
     * DUAL CONTROL ENFORCEMENT (Legal Cases only, per confirmed plan):
     * $authorizedBy must differ from the row's disposal_requested_by.
     * This check happens here, server-side, specifically so a direct
     * POST to the authorize action can never bypass it - the UI
     * disabling the button for the requester is a courtesy, not the
     * actual control.
     *
     * Returns a result array: ['ok' => bool, 'error' => string|null]
     * rather than a bare bool, since the caller needs to distinguish
     * "not found / wrong state" from "same-person dual-control block"
     * to show the right flash message.
     */
    function t8_retention_authorize_disposal(PDO $pdo, int $recordId, int $authorizedBy): array
    {
        $record = t8_retention_fetch($pdo, $recordId);
        if (!$record || $record['entity_type'] === 'document' || $record['status'] !== 'pending_disposal') {
            return ['ok' => false, 'error' => 'That disposal request is no longer available for authorization.'];
        }

        $rule = t8_retention_disposal_rule((string) $record['entity_type']);
        if ($rule['dual_control'] && (int) $record['disposal_requested_by'] === $authorizedBy) {
            return ['ok' => false, 'error' => 'This disposal was requested by you. A different administrator must authorize it.'];
        }

        $stmt = $pdo->prepare(
            "UPDATE team8_records SET status = 'disposed',
                disposal_authorized_by = :authorized_by, disposal_authorized_at = NOW(), disposed_at = NOW()
             WHERE id = :id AND status = 'pending_disposal'"
        );
        $stmt->execute(['authorized_by' => $authorizedBy, 'id' => $recordId]);

        return $stmt->rowCount() > 0
            ? ['ok' => true, 'error' => null]
            : ['ok' => false, 'error' => 'That disposal request could not be completed.'];
    }
}

if (!function_exists('t8_retention_due_soon')) {
    /** Records whose disposition_date falls within the next $days, still active/due_review. */
    function t8_retention_due_soon(PDO $pdo, int $days = 90, ?string $entityType = null, int $limit = 10): array
    {
        $sql = "SELECT r.*, u.full_name AS custodian_name
                FROM team8_records r
                JOIN users u ON u.id = r.custodian_id
                WHERE r.status IN ('active', 'due_review')
                  AND r.disposition_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)";
        $params = ['days' => $days];
        if ($entityType !== null) {
            $sql .= ' AND r.entity_type = :entity_type';
            $params['entity_type'] = $entityType;
        }
        $sql .= ' ORDER BY r.disposition_date ASC LIMIT ' . max(1, $limit);

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('t8_retention_refresh_due_review')) {
    /**
     * Flips 'active' rows to 'due_review' once they are within 90 days
     * of disposition - called once per request from the retention
     * landing page, same "evaluate on load" pattern already used by
     * t8_expire_visitor_bookings() and the reservation lifecycle sweep.
     */
    function t8_retention_refresh_due_review(PDO $pdo, int $actorId, int $warningDays = 90): void
    {
        $ids = $pdo->prepare(
            "SELECT id FROM team8_records
             WHERE status = 'active' AND disposition_date <= DATE_ADD(CURDATE(), INTERVAL :days DAY)"
        );
        $ids->execute(['days' => $warningDays]);
        $ids = $ids->fetchAll(PDO::FETCH_COLUMN);
        if ($ids === []) {
            return;
        }

        $pdo->prepare(
            "UPDATE team8_records SET status = 'due_review'
             WHERE status = 'active' AND disposition_date <= DATE_ADD(CURDATE(), INTERVAL :days DAY)"
        )->execute(['days' => $warningDays]);

        foreach ($ids as $id) {
            t8_audit_log($pdo, $actorId, 'retention_record', (int) $id, 'due_review', 'active', 'disposition date approaching');
        }
    }
}

if (!function_exists('t8_retention_orphan_count')) {
    /**
     * Counts business records with NO retention row registered yet,
     * per entity type - powers the unified dashboard's "compliance"
     * panel so nothing silently skips retention tracking.
     */
    function t8_retention_orphan_count(PDO $pdo, string $entityType): int
    {
        $sql = match ($entityType) {
            'document' => "SELECT COUNT(*) FROM team8_documents d
                            WHERE d.deleted_at IS NULL
                              AND NOT EXISTS (SELECT 1 FROM team8_records r WHERE r.entity_type = 'document' AND r.entity_id = d.id)",
            'contract' => "SELECT COUNT(*) FROM team8_contracts c
                            WHERE c.deleted_at IS NULL
                              AND NOT EXISTS (SELECT 1 FROM team8_records r WHERE r.entity_type = 'contract' AND r.entity_id = c.id)",
            'legal_case' => "SELECT COUNT(*) FROM team8_legal_cases lc
                            WHERE lc.deleted_at IS NULL
                              AND NOT EXISTS (SELECT 1 FROM team8_records r WHERE r.entity_type = 'legal_case' AND r.entity_id = lc.id)",
            default => throw new InvalidArgumentException("Unknown retention entity type: {$entityType}"),
        };

        return (int) $pdo->query($sql)->fetchColumn();
    }
}
