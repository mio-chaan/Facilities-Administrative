<?php
declare(strict_types=1);

function t8_document_tracking_schema_supported(PDO $pdo): bool
{
    static $supported = null;
    if ($supported !== null) {
        return $supported;
    }
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'team8_documents'
           AND column_name IN ('tracking_status', 'last_checked_at')"
    );
    $supported = (int) $stmt->fetchColumn() === 2;
    return $supported;
}

function t8_document_sync_tracking(PDO $pdo, array $document, ?int $actorId = null): array
{
    $tracking = calculateTrackingStatus(isset($document['expiration_date']) ? (string) $document['expiration_date'] : null);
    $id = (int) ($document['id'] ?? 0);
    if ($id <= 0 || !t8_document_tracking_schema_supported($pdo)) {
        return array_merge($document, [
            'tracking_status' => $tracking['status'],
            'days_remaining' => $tracking['daysRemaining'],
            'alert_level' => $tracking['alertLevel'],
        ]);
    }

    $oldStatus = (string) ($document['tracking_status'] ?? 'Valid');
    $changed = $oldStatus !== $tracking['status'];
    $timestamp = date('Y-m-d H:i:s');
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $update = $pdo->prepare(
            'UPDATE team8_documents
             SET tracking_status = :tracking_status, last_checked_at = NOW(), updated_at = updated_at
             WHERE id = :id'
        );
        $update->execute(['tracking_status' => $tracking['status'], 'id' => $id]);
        if ($changed && function_exists('t8_audit_log')) {
            $actorId ??= function_exists('t8_current_user_id') ? t8_current_user_id() : null;
            $title = (string) ($document['title'] ?? ('Document #' . $id));
            $days = $tracking['daysRemaining'] === null ? 'not applicable' : (string) $tracking['daysRemaining'];
            t8_audit_log(
                $pdo,
                $actorId,
                'document',
                $id,
                'Expiration tracking status changed',
                $title . ' | ' . $oldStatus,
                $title . ' | ' . $tracking['status'] . ' | days remaining: ' . $days . ' | checked at: ' . $timestamp,
                true
            );
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return array_merge($document, [
        'tracking_status' => $tracking['status'],
        'days_remaining' => $tracking['daysRemaining'],
        'alert_level' => $tracking['alertLevel'],
        'last_checked_at' => $timestamp,
    ]);
}

function t8_document_sync_tracking_scope(PDO $pdo, ?int $uploadedBy = null, ?int $categoryId = null, ?int $actorId = null): void
{
    $where = ["d.deleted_at IS NULL", "d.status IN ('approved', 'Renewed')"];
    $params = [];
    if ($uploadedBy !== null && $uploadedBy > 0) {
        $where[] = 'd.uploaded_by = :uploaded_by';
        $params['uploaded_by'] = $uploadedBy;
    }
    if ($categoryId !== null && $categoryId > 0) {
        $where[] = 'd.category_id = :category_id';
        $params['category_id'] = $categoryId;
    }
    $stmt = $pdo->prepare(
        'SELECT d.id, d.title, d.expiration_date, d.tracking_status
         FROM team8_documents d WHERE ' . implode(' AND ', $where)
    );
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $document) {
        t8_document_sync_tracking($pdo, $document, $actorId);
    }
}

function t8_document_tracking_badge(array $tracking): string
{
    $level = (string) ($tracking['alert_level'] ?? $tracking['alertLevel'] ?? 'Normal');
    $days = $tracking['days_remaining'] ?? $tracking['daysRemaining'] ?? null;
    $class = match ($level) {
        'Danger' => 'expired',
        'Critical' => 'critical',
        'Warning' => 'warning',
        default => 'valid',
    };
    $label = match ($level) {
        'Danger' => 'Expired',
        'Critical' => 'Critical' . ($days !== null ? ' (' . (int) $days . 'd)' : ''),
        'Warning' => 'Warning' . ($days !== null ? ' (' . (int) $days . 'd)' : ''),
        default => 'Valid',
    };
    $status = (string) ($tracking['tracking_status'] ?? $tracking['status'] ?? 'Valid');
    $title = $days === null ? $status : $status . '; ' . (int) $days . ' days remaining';
    return '<span class="t8-badge t8-badge-tracking t8-badge-tracking-' . $class . '" title="'
        . e($title) . '">' . e($label) . '</span>';
}

function t8_document_tracking_for_report(PDO $pdo, ?int $departmentId, int $actorId): array
{
    $where = ["c.name = 'Compliance'", 'd.deleted_at IS NULL', "d.status NOT IN ('pending', 'rejected', 'archived')"];
    $params = [];
    if ($departmentId !== null && $departmentId > 0) {
        $where[] = 'd.department_id = :department_id';
        $params['department_id'] = $departmentId;
    }
    $stmt = $pdo->prepare(
        'SELECT d.id, d.title, d.document_type, d.expiration_date, d.tracking_status, d.status,
                dep.name AS department_name
         FROM team8_documents d
         JOIN team8_document_categories c ON c.id = d.category_id
         LEFT JOIN departments dep ON dep.id = d.department_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY d.title, d.id'
    );
    $stmt->execute($params);
    $documents = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $document) {
        $documents[] = t8_document_sync_tracking($pdo, $document, $actorId);
    }
    return $documents;
}

function t8_document_tracking_summary(array $documents): array
{
    $counts = ['valid' => 0, 'expiring_soon' => 0, 'expired' => 0];
    foreach ($documents as $document) {
        $status = (string) ($document['tracking_status'] ?? 'Valid');
        if ($status === 'Expired') {
            $counts['expired']++;
        } elseif ($status === 'Expiring Soon') {
            $counts['expiring_soon']++;
        } else {
            $counts['valid']++;
        }
    }
    $counts['summary'] = sprintf(
        'Document expiration tracking: %d valid, %d expiring soon, and %d expired.',
        $counts['valid'],
        $counts['expiring_soon'],
        $counts['expired']
    );
    return $counts;
}
