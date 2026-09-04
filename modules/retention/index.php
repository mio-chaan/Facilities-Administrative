<?php
/**
 * modules/retention/index.php
 * Records Retention & Compliance.
 *
 * Two linked concepts, matching the schema:
 *   team8_retention_schedules - retention POLICIES (record_type + how
 *     many years to keep it), e.g. "Financial Records" / 7 years.
 *   team8_records - one row per document placed under a retention
 *     schedule: which document, which schedule, who the custodian is,
 *     and when it's due for disposition.
 *
 * disposition_date defaults to (today + schedule's retention_years)
 * when creating a record, but staff can override it. A record is
 * "Overdue" purely by comparing disposition_date to today - status
 * itself only tracks active/disposed. Archiving uses deleted_at
 * (soft delete), same convention as Document Management.
 *
 * Any logged-in user (staff or admin) can manage schedules and records.
 */

declare(strict_types=1);

$pageTitle = 'Records Retention';
$currentUserId = t8_current_user_id();
$isAdmin = t8_has_role('admin');
$action = $_GET['action'] ?? 'list';
$errors = [];

// Retention policy, record creation, archival and disposal are official
// records operations.  Staff can only view records for which they are the
// recorded custodian.
if (!$isAdmin && in_array($action, ['create_schedule', 'create_record', 'archive', 'restore', 'mark_for_disposal', 'dispose', 'reactivate'], true)) {
    t8_require_role(['admin']);
}

/** Fetch one record row with joined display names, or null. */
function t8_retention_record_fetch(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, d.title AS document_title, s.record_type, s.retention_years, u.full_name AS custodian_name
         FROM team8_records r
         JOIN team8_documents d ON d.id = r.document_id
         JOIN team8_retention_schedules s ON s.id = r.schedule_id
         JOIN users u ON u.id = r.custodian_id
         WHERE r.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// Dropdown data used by the "create record" form.
$documents = $pdo->query(
    'SELECT id, title FROM team8_documents WHERE deleted_at IS NULL ORDER BY title'
)->fetchAll(PDO::FETCH_ASSOC);
$schedules = $pdo->query(
    'SELECT id, record_type, retention_years FROM team8_retention_schedules ORDER BY record_type'
)->fetchAll(PDO::FETCH_ASSOC);
$custodians = $pdo->query(
    'SELECT id, full_name FROM users ORDER BY full_name'
)->fetchAll(PDO::FETCH_ASSOC);

switch ($action) {
    case 'create_schedule':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $recordType = trim((string) ($_POST['record_type'] ?? ''));
            $retentionYears = (int) ($_POST['retention_years'] ?? 0);

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                if ($recordType === '') {
                    $errors[] = 'Record type is required.';
                }
                if ($retentionYears < 1) {
                    $errors[] = 'Retention period must be at least 1 year.';
                }

                if (!$errors) {
                    $pdo->prepare(
                        'INSERT INTO team8_retention_schedules (record_type, retention_years) VALUES (:record_type, :retention_years)'
                    )->execute(['record_type' => $recordType, 'retention_years' => $retentionYears]);
                    $newId = (int) $pdo->lastInsertId();
                    t8_audit_log($pdo, $currentUserId, 'retention_schedule', $newId, 'create');
                    t8_flash_set('success', 'Retention schedule added.');
                    redirect(page_url('retention'));
                }
            }
        }
        break;

    case 'create_record':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $documentId = (int) ($_POST['document_id'] ?? 0);
            $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
            $custodianId = (int) ($_POST['custodian_id'] ?? 0);
            $dispositionDate = trim((string) ($_POST['disposition_date'] ?? ''));

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                if (!$documentId) {
                    $errors[] = 'Please select a document.';
                }
                if (!$scheduleId) {
                    $errors[] = 'Please select a retention schedule.';
                }
                if (!$custodianId) {
                    $errors[] = 'Please select a custodian.';
                }
                if ($dispositionDate === '' || strtotime($dispositionDate) === false) {
                    $errors[] = 'Disposition date must be a valid date.';
                }

                if (!$errors) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO team8_records (document_id, schedule_id, custodian_id, disposition_date, status)
                         VALUES (:document_id, :schedule_id, :custodian_id, :disposition_date, "active")'
                    );
                    $stmt->execute([
                        'document_id'      => $documentId,
                        'schedule_id'      => $scheduleId,
                        'custodian_id'     => $custodianId,
                        'disposition_date' => $dispositionDate,
                    ]);
                    $newId = (int) $pdo->lastInsertId();
                    t8_audit_log($pdo, $currentUserId, 'record', $newId, 'create');
                    t8_flash_set('success', 'Record placed under retention.');
                    redirect(page_url('retention'));
                }
            }
        }
        break;

    case 'mark_for_disposal':
    case 'dispose':
    case 'reactivate':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('retention'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('retention'));
        }
        $id = (int) ($_POST['id'] ?? 0);
        $record = t8_retention_record_fetch($pdo, $id);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($record && $action === 'mark_for_disposal' && $record['status'] === 'archived' && $reason !== '') {
            $pdo->prepare("UPDATE team8_records SET status = 'for_disposal', disposal_reason = :reason WHERE id = :id")
                ->execute(['reason' => $reason, 'id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'record', $id, 'for_disposal', 'archived', $reason);
            t8_flash_set('success', 'Record marked for disposal.');
        } elseif ($record && $action === 'dispose' && $record['status'] === 'for_disposal' && $reason !== '') {
            $pdo->prepare("UPDATE team8_records SET status = 'disposed', disposed_at = NOW(), disposal_reason = :reason WHERE id = :id")
                ->execute(['reason' => $reason, 'id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'record', $id, 'dispose', 'for_disposal', $reason);
            t8_flash_set('success', 'Record marked as disposed.');
        } elseif ($record && $action === 'reactivate') {
            $pdo->prepare("UPDATE team8_records SET status = 'active', deleted_at = NULL, archived_at = NULL WHERE id = :id")
                ->execute(['id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'record', $id, 'reactivate');
            t8_flash_set('success', 'Record reactivated.');
        } else {
            t8_flash_set('danger', 'A valid record status and reason are required.');
        }
        redirect(page_url('retention'));
        break;

    case 'archive':
    case 'restore':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('retention'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('retention'));
        }
        $id = (int) ($_POST['id'] ?? 0);
        $record = t8_retention_record_fetch($pdo, $id);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($record && $action === 'archive' && $record['status'] === 'active' && $reason !== '') {
            $pdo->prepare("UPDATE team8_records SET status = 'archived', deleted_at = NOW(), archived_at = NOW(), archive_reason = :reason WHERE id = :id")
                ->execute(['reason' => $reason, 'id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'record', $id, 'archive', 'active', $reason);
            t8_flash_set('success', 'Record archived.');
        } elseif ($record && $action === 'restore' && $record['status'] === 'archived') {
            $pdo->prepare("UPDATE team8_records SET status = 'active', deleted_at = NULL, archived_at = NULL WHERE id = :id")
                ->execute(['id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'record', $id, 'restore', 'archived', 'active');
            t8_flash_set('success', 'Record restored.');
        } else {
            t8_flash_set('danger', 'An active record and archive reason are required.');
        }
        redirect(page_url('retention'));
        break;
}

$showScheduleForm = $action === 'create_schedule';
$showRecordForm = $action === 'create_record';
$showList = !$showScheduleForm && !$showRecordForm;
$retentionStats = ['total' => 0, 'active' => 0, 'ending' => 0, 'archived' => 0, 'compliance' => 100];

if ($showList) {
    $statusFilter = ($_GET['status'] ?? 'active') === 'archived' ? 'archived' : 'active';
    $whereClause = $statusFilter === 'archived' ? 'r.deleted_at IS NOT NULL' : 'r.deleted_at IS NULL';
    $scopeSql = $isAdmin ? '' : ' AND r.custodian_id = :user_id';
    $recordsPageSize = 10;
    $recordsPage = max(1, (int) ($_GET['retention_page'] ?? 1));
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM team8_records r WHERE $whereClause$scopeSql");
    $countStmt->execute($isAdmin ? [] : ['user_id' => $currentUserId]);
    $recordsTotal = (int) $countStmt->fetchColumn();
    $recordsTotalPages = max(1, (int) ceil($recordsTotal / $recordsPageSize));
    $recordsPage = min($recordsPage, $recordsTotalPages);
    $recordsStmt = $pdo->prepare(
        "SELECT r.*, d.title AS document_title, s.record_type, s.retention_years, u.full_name AS custodian_name
         FROM team8_records r
         JOIN team8_documents d ON d.id = r.document_id
         JOIN team8_retention_schedules s ON s.id = r.schedule_id
         JOIN users u ON u.id = r.custodian_id
         WHERE $whereClause$scopeSql
         ORDER BY r.disposition_date ASC
         LIMIT {$recordsPageSize} OFFSET " . (($recordsPage - 1) * $recordsPageSize)
    );
    $recordsStmt->execute($isAdmin ? [] : ['user_id' => $currentUserId]);
    $records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($isAdmin) {
        $endingSoon = $pdo->query("SELECT r.*, d.title AS document_title FROM team8_records r JOIN team8_documents d ON d.id = r.document_id WHERE r.status = 'active' AND r.disposition_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY r.disposition_date ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    $retentionStats['total'] = (int) $pdo->query('SELECT COUNT(*) FROM team8_records')->fetchColumn();
    $retentionStats['active'] = (int) $pdo->query("SELECT COUNT(*) FROM team8_records WHERE status = 'active' AND deleted_at IS NULL")->fetchColumn();
    $retentionStats['archived'] = (int) $pdo->query("SELECT COUNT(*) FROM team8_records WHERE status = 'archived' OR deleted_at IS NOT NULL")->fetchColumn();
    $retentionStats['ending'] = (int) $pdo->query("SELECT COUNT(*) FROM team8_records WHERE status = 'active' AND disposition_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
    $retentionStats['compliance'] = $retentionStats['total'] > 0 ? (int) round(($retentionStats['active'] / $retentionStats['total']) * 100) : 100;
}

/** Suggests a disposition_date = today + N years, for the create-record form default. */
function t8_retention_suggest_date(int $years): string
{
    return date('Y-m-d', strtotime("+{$years} years"));
}
?>
<div class="t8-retention-heading">
    <div><h1>Records Retention</h1><p class="t8-help-text">Track retention policies, disposition schedules, and compliance for records.</p></div>
    <?php if ($showList): ?><a class="t8-btn t8-btn-accent" href="<?= e(page_url('retention', ['action' => 'create_schedule'])) ?>"><i class="fa-solid fa-plus"></i> Add Schedule</a><?php endif; ?>
</div>

<?php foreach ($errors as $error): ?>
    <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if ($showScheduleForm): ?>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title">New Retention Schedule</h2>
        </div>
        <form method="post" action="<?= e(page_url('retention', ['action' => 'create_schedule'])) ?>" novalidate>
            <?= t8_csrf_field() ?>

            <div class="t8-field">
                <label class="t8-label" for="record_type">Record Type</label>
                <input class="t8-input" type="text" id="record_type" name="record_type"
                       placeholder="e.g. Financial Records" required>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="retention_years">Retention Period (years)</label>
                <input class="t8-input" type="number" id="retention_years" name="retention_years" min="1" required>
            </div>

            <button class="t8-btn t8-btn-accent" type="submit">
                <i class="fa-solid fa-check"></i> Save Schedule
            </button>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('retention')) ?>">Cancel</a>
        </form>
    </div>

<?php elseif ($showRecordForm): ?>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Place a Document Under Retention</h2>
        </div>

        <?php if ($documents === [] || $schedules === []): ?>
            <div class="t8-empty">
                <?php if ($documents === []): ?>
                    No documents are available yet. Upload one in Document Management first.
                <?php else: ?>
                    No retention schedules exist yet. <a href="<?= e(page_url('retention', ['action' => 'create_schedule'])) ?>">Add one first.</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <form method="post" action="<?= e(page_url('retention', ['action' => 'create_record'])) ?>" novalidate>
                <?= t8_csrf_field() ?>

                <div class="t8-field">
                    <label class="t8-label" for="document_id">Document</label>
                    <select class="t8-select" id="document_id" name="document_id" required>
                        <option value="">Select a document…</option>
                        <?php foreach ($documents as $d): ?>
                            <option value="<?= e((string) $d['id']) ?>"><?= e($d['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="schedule_id">Retention Schedule</label>
                    <select class="t8-select" id="schedule_id" name="schedule_id" required
                            onchange="const y=this.options[this.selectedIndex].dataset.years; if(y){const d=new Date(); d.setFullYear(d.getFullYear()+parseInt(y)); document.getElementById('disposition_date').value=d.toISOString().slice(0,10);}">
                        <option value="">Select a schedule…</option>
                        <?php foreach ($schedules as $s): ?>
                            <option value="<?= e((string) $s['id']) ?>" data-years="<?= e((string) $s['retention_years']) ?>">
                                <?= e($s['record_type']) ?> (<?= e((string) $s['retention_years']) ?> yrs)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="custodian_id">Custodian</label>
                    <select class="t8-select" id="custodian_id" name="custodian_id" required>
                        <option value="">Select a custodian…</option>
                        <?php foreach ($custodians as $c): ?>
                            <option value="<?= e((string) $c['id']) ?>" <?= (int) $c['id'] === $currentUserId ? 'selected' : '' ?>>
                                <?= e($c['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="disposition_date">Disposition Date</label>
                    <input class="t8-input" type="date" id="disposition_date" name="disposition_date"
                           value="<?= e(t8_retention_suggest_date(1)) ?>" required>
                    <span class="t8-help-text">Auto-filled from the schedule's retention period once selected — adjust if needed.</span>
                </div>

                <button class="t8-btn t8-btn-accent" type="submit">
                    <i class="fa-solid fa-check"></i> Save Record
                </button>
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('retention')) ?>">Cancel</a>
            </form>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="t8-retention-kpi-grid" aria-label="Retention summary">
        <div class="t8-retention-kpi"><div class="t8-retention-kpi-icon"><i class="fa-solid fa-folder-open"></i></div><div><span>Total Records</span><strong><?= e((string) $retentionStats['total']) ?></strong><small>All tracked records</small></div></div>
        <div class="t8-retention-kpi t8-retention-kpi-green"><div class="t8-retention-kpi-icon"><i class="fa-solid fa-shield-halved"></i></div><div><span>Active Retention</span><strong><?= e((string) $retentionStats['active']) ?></strong><small>Currently protected</small></div></div>
        <div class="t8-retention-kpi t8-retention-kpi-orange"><div class="t8-retention-kpi-icon"><i class="fa-solid fa-clock"></i></div><div><span>Ending Soon</span><strong><?= e((string) $retentionStats['ending']) ?></strong><small>Next 30 days</small></div></div>
        <div class="t8-retention-kpi t8-retention-kpi-gray"><div class="t8-retention-kpi-icon"><i class="fa-solid fa-box-archive"></i></div><div><span>Archived</span><strong><?= e((string) $retentionStats['archived']) ?></strong><small>Historical records</small></div></div>
    </div>

    <?php if ($isAdmin): ?>
        <div class="t8-retention-summary-grid">
        <div class="t8-card" style="margin-bottom:var(--t8-space-4);">
            <div class="t8-card-header"><h2 class="t8-card-title">Retention Ending Soon</h2></div>
            <?php if ($endingSoon === []): ?><div class="t8-empty">No active records reach the end of retention in the next 30 days.</div>
            <?php else: ?><div class="t8-table-wrap"><table class="t8-table"><thead><tr><th>Record</th><th>Expiration Date</th></tr></thead><tbody><?php foreach ($endingSoon as $soon): ?><tr><td><?= e($soon['document_title']) ?></td><td><?= e(format_date($soon['disposition_date'], 'M d, Y')) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </div>
        <div class="t8-card t8-compliance-card">
            <div class="t8-card-header"><h2 class="t8-card-title"><i class="fa-solid fa-chart-pie"></i> Compliance Status</h2><a class="t8-card-link" href="#retention-records">View Compliance <span aria-hidden="true">→</span></a></div>
            <div class="t8-compliance-score"><strong><?= e((string) $retentionStats['compliance']) ?>%</strong><span>compliant records</span></div>
            <div class="t8-compliance-bar"><span style="width:<?= e((string) $retentionStats['compliance']) ?>%"></span></div>
            <div class="t8-compliance-meta"><span><i class="fa-solid fa-circle-check"></i> <?= e((string) $retentionStats['active']) ?> active</span><span><i class="fa-solid fa-circle-exclamation"></i> <?= e((string) $retentionStats['ending']) ?> need attention</span></div>
        </div>
        </div>
    <?php endif; ?>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Retention Schedules</h2>
            <a class="t8-btn t8-btn-accent" href="<?= e(page_url('retention', ['action' => 'create_schedule'])) ?>">
                <i class="fa-solid fa-plus"></i> Add Schedule
            </a>
        </div>
        <?php if ($schedules === []): ?>
            <div class="t8-empty">No retention schedules defined yet.</div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr>
                            <th>Record Type</th>
                            <th>Retention Period</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schedules as $s): ?>
                            <tr>
                                <td><?= e($s['record_type']) ?></td>
                                <td><?= e((string) $s['retention_years']) ?> year<?= (int) $s['retention_years'] === 1 ? '' : 's' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div id="t8RetentionToolbar" class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-accent" href="<?= e(page_url('retention', ['action' => 'create_record'])) ?>">
            <i class="fa-solid fa-plus"></i> Place Document Under Retention
        </a>
        <?php if ($statusFilter === 'active'): ?>
            <a id="t8RetentionToggle" class="t8-btn t8-btn-outline" href="<?= e(page_url('retention', ['status' => 'archived'])) ?>">
                <i class="fa-solid fa-box-archive"></i> View Archived
            </a>
        <?php else: ?>
            <a id="t8RetentionToggle" class="t8-btn t8-btn-outline" href="<?= e(page_url('retention')) ?>">
                <i class="fa-solid fa-list"></i> View Active
            </a>
        <?php endif; ?>
    </div>

    <div id="t8RetentionTableShell">
        <div class="t8-card" id="retention-records">
            <div class="t8-card-header">
                <h2 class="t8-card-title"><?= $statusFilter === 'archived' ? 'Archived Records' : 'Records Under Retention' ?></h2>
            </div>
        <?php if ($records === []): ?>
            <div class="t8-empty">
                <?= $statusFilter === 'archived' ? 'No archived records.' : 'No records under retention yet.' ?>
            </div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th>Record Type</th>
                            <th>Custodian</th>
                            <th>Disposition Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                            <?php
                                $isOverdue = $r['status'] === 'active' && strtotime($r['disposition_date']) < strtotime('today');
                                $isExpiring = $r['status'] === 'active' && strtotime($r['disposition_date']) <= strtotime('+30 days');
                            ?>
                            <tr>
                                <td><?= e($r['document_title']) ?></td>
                                <td><?= e($r['record_type']) ?> (<?= e((string) $r['retention_years']) ?> yrs)</td>
                                <td><?= e($r['custodian_name']) ?></td>
                                <td><?= e(format_date($r['disposition_date'], 'M d, Y')) ?></td>
                                <td>
                                    <?php if ($r['status'] === 'disposed'): ?>
                                        <span class="t8-badge t8-badge-rejected">Disposed</span>
                                    <?php elseif ($r['status'] === 'for_disposal'): ?>
                                        <span class="t8-badge t8-badge-pending">For Disposal</span>
                                    <?php elseif ($r['status'] === 'archived'): ?>
                                        <span class="t8-badge t8-badge-archived">Archived</span>
                                    <?php elseif ($isOverdue): ?>
                                        <span class="t8-badge t8-badge-pending">
                                            <i class="fa-solid fa-triangle-exclamation"></i> Overdue
                                        </span>
                                    <?php else: ?>
                                        <span class="t8-badge <?= $isExpiring ? 't8-badge-pending' : 't8-badge-approved' ?>"><?= $isExpiring ? 'Expiring' : 'Active' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <?php if ($isAdmin && $statusFilter === 'active'): ?>
                                        <?php if ($r['status'] === 'for_disposal'): ?>
                                            <form method="post" action="<?= e(page_url('retention', ['action' => 'dispose'])) ?>"><input type="hidden" name="id" value="<?= e((string) $r['id']) ?>"><?= t8_csrf_field() ?><input class="t8-input" name="reason" placeholder="Disposal reason" required><button class="t8-btn t8-btn-danger t8-btn-sm" type="submit">Dispose</button></form>
                                        <?php endif; ?>
                                        <form method="post" action="<?= e(page_url('retention', ['action' => 'archive'])) ?>"
                                              onsubmit="return confirm('Archive this record?');">
                                            <?= t8_csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $r['id']) ?>">
                                            <input class="t8-input" name="reason" placeholder="Archive reason" required>
                                            <button class="t8-btn t8-btn-outline t8-btn-sm" type="submit">
                                                <i class="fa-solid fa-box-archive"></i> Archive
                                            </button>
                                        </form>
                                    <?php elseif ($isAdmin && $r['status'] === 'archived'): ?>
                                        <form method="post" action="<?= e(page_url('retention', ['action' => 'mark_for_disposal'])) ?>"><input type="hidden" name="id" value="<?= e((string) $r['id']) ?>"><?= t8_csrf_field() ?><input class="t8-input" name="reason" placeholder="Disposal reason" required><button class="t8-btn t8-btn-danger t8-btn-sm" type="submit">For Disposal</button></form>
                                        <form method="post" action="<?= e(page_url('retention', ['action' => 'restore'])) ?>">
                                            <?= t8_csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $r['id']) ?>">
                                            <button class="t8-btn t8-btn-success t8-btn-sm" type="submit">
                                                <i class="fa-solid fa-rotate-left"></i> Restore
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
        <?php if ($recordsTotalPages > 1): ?>
            <nav class="t8-pagination" aria-label="Retention record pages">
                <?php if ($recordsPage > 1): ?><a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('retention', ['status' => $statusFilter, 'retention_page' => $recordsPage - 1])) ?>">Previous</a><?php endif; ?>
                <span class="t8-help-text">Showing <?= e((string) (($recordsPage - 1) * $recordsPageSize + 1)) ?>–<?= e((string) min($recordsPage * $recordsPageSize, $recordsTotal)) ?> of <?= e((string) $recordsTotal) ?></span>
                <?php if ($recordsPage < $recordsTotalPages): ?><a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('retention', ['status' => $statusFilter, 'retention_page' => $recordsPage + 1])) ?>">Next</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>

<?php endif; ?>
