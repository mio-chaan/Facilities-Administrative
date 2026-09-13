<?php
/**
 * modules/retention/index.php
 * Records Retention & Compliance.
 *
 * PHASE 4 (Document/Legal/Contract/Retention rebuild) — FULL REBUILD
 * against the Phase 1 polymorphic team8_records schema, spanning
 * Documents, Contracts, and Legal Cases.
 *
 * PHASE 5 (this revision) — UNIFIED DASHBOARD.
 * The landing view ($showList below, i.e. no ?action= or the default)
 * is now the system's single cross-module dashboard: per-entity-type
 * KPI cards, a combined activity feed reading straight from
 * audit_logs (no new events table needed - every module already
 * writes there), and three compact "coming up" lists, one per entity
 * type, each linking straight into its own module rather than
 * duplicating that module's full table here.
 *
 * Deliberately built ON TOP of this existing module rather than as a
 * new route/sidebar entry: Retention is the one module that already
 * has a foot in all three other modules by design (Phase 1-4), so
 * this is where the cross-module view naturally belongs.
 *
 * team8_records now retains any of THREE business record types:
 *   'document'    -> team8_documents.id      (single-step disposal)
 *   'contract'    -> team8_contracts.id      (two-step disposal, single admin)
 *   'legal_case'  -> team8_legal_cases.id    (two-step disposal, DUAL CONTROL -
 *                                             requester and authorizer must differ)
 *
 * Contracts and Legal Cases register themselves automatically the
 * moment they reach end-of-life. Documents are registered at upload
 * time (modules/documents/index.php). Any of the three can equally be
 * managed from its own module's "Retention" screen or from here -
 * both act on the exact same row via the same Phase 1 helpers.
 *
 * Any logged-in user (staff or admin) can VIEW records and the
 * dashboard; only admins can register, archive, or dispose.
 */

declare(strict_types=1);

$retentionHelperPath = __DIR__ . '/../../app/includes/retention_helpers.php';
if (is_file($retentionHelperPath)) {
    require_once $retentionHelperPath;
}

$pageTitle = 'Records Retention';
$currentUserId = t8_current_user_id();
$isAdmin = t8_has_role('admin');
$action = $_GET['action'] ?? 'list';
$errors = [];

if (!function_exists('t8_retention_resolve_entity')) {
    ?>
    <h1>Records Retention</h1>
    <div class="t8-alert t8-alert-danger">
        Retention features require <code>app/includes/retention_helpers.php</code> (Phase 1) and the
        <code>team8_records</code> polymorphic migration to be deployed first.
    </div>
    <?php
    return;
}

if (!$isAdmin && in_array($action, ['register', 'archive', 'dispose', 'dispose_request', 'dispose_authorize'], true)) {
    t8_require_role(['admin']);
}

t8_retention_refresh_due_review($pdo, (int) $currentUserId);

$entityTypeLabels = [
    'document'   => 'Document',
    'contract'   => 'Contract',
    'legal_case' => 'Legal Case',
];

function t8_retention_activity_label(string $action, string $entityType): string
{
    $entity = str_replace('_', ' ', $entityType);
    $verb = str_replace('_', ' ', $action);
    return ucfirst(trim($entity . ' ' . $verb));
}

switch ($action) {
    case 'register':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $entityType = (string) ($_POST['entity_type'] ?? '');
            $entityId = (int) ($_POST['entity_id'] ?? 0);
            $basis = trim((string) ($_POST['retention_basis'] ?? ''));
            $years = (int) ($_POST['retention_years'] ?? 0);
            $startDate = trim((string) ($_POST['retention_start_date'] ?? ''));
            $custodianId = (int) ($_POST['custodian_id'] ?? $currentUserId);

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                if (!in_array($entityType, T8_RETENTION_ENTITY_TYPES, true)) {
                    $errors[] = 'Please select a valid record type.';
                }
                if ($entityId < 1) {
                    $errors[] = 'Please select a specific record.';
                }
                if ($basis === '') {
                    $errors[] = 'Retention basis is required.';
                }
                if ($years < 1) {
                    $errors[] = 'Retention period must be at least 1 year.';
                }
                if ($startDate === '' || strtotime($startDate) === false) {
                    $errors[] = 'Retention start date must be a valid date.';
                }
                if (!$errors && t8_retention_fetch_for_entity($pdo, $entityType, $entityId) !== null) {
                    $errors[] = 'This record is already registered under retention. Edit it from its detail view instead.';
                }
                if (!$errors && t8_retention_resolve_entity($pdo, $entityType, $entityId) === null) {
                    $errors[] = 'That record could not be found.';
                }

                if (!$errors) {
                    $recordId = t8_retention_register($pdo, $entityType, $entityId, $basis, $years, $startDate, $custodianId ?: $currentUserId);
                    t8_audit_log($pdo, $currentUserId, 'retention_record', $recordId, 'create');
                    t8_flash_set('success', 'Record registered under retention.');
                    redirect(page_url('retention', ['action' => 'view', 'id' => $recordId]));
                }
            }
        }

        $candidateDocuments = $pdo->query(
            "SELECT d.id, d.title FROM team8_documents d
             WHERE d.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM team8_records r WHERE r.entity_type = 'document' AND r.entity_id = d.id)
             ORDER BY d.title"
        )->fetchAll(PDO::FETCH_ASSOC);
        $candidateContracts = $pdo->query(
            "SELECT c.id, c.title FROM team8_contracts c
             WHERE c.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM team8_records r WHERE r.entity_type = 'contract' AND r.entity_id = c.id)
             ORDER BY c.title"
        )->fetchAll(PDO::FETCH_ASSOC);
        $candidateLegalCases = $pdo->query(
            "SELECT lc.id, lc.title FROM team8_legal_cases lc
             WHERE lc.deleted_at IS NULL AND lc.status = 'closed'
               AND NOT EXISTS (SELECT 1 FROM team8_records r WHERE r.entity_type = 'legal_case' AND r.entity_id = lc.id)
             ORDER BY lc.title"
        )->fetchAll(PDO::FETCH_ASSOC);
        $custodianOptions = $pdo->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'archive':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('retention'));
        }
        $recordId = (int) ($_POST['id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '') {
            t8_flash_set('danger', 'An archive reason is required.');
        } elseif (t8_retention_archive($pdo, $recordId, $reason)) {
            t8_audit_log($pdo, $currentUserId, 'retention_record', $recordId, 'archive', null, $reason);
            t8_flash_set('success', 'Record archived.');
        } else {
            t8_flash_set('danger', 'That record could not be archived from its current state.');
        }
        redirect(page_url('retention', ['action' => 'view', 'id' => $recordId]));
        break;

    case 'dispose':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('retention'));
        }
        $recordId = (int) ($_POST['id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '') {
            t8_flash_set('danger', 'A disposal reason is required.');
        } elseif (t8_retention_dispose_document($pdo, $recordId, $currentUserId, $reason)) {
            t8_audit_log($pdo, $currentUserId, 'retention_record', $recordId, 'disposed', null, $reason);
            t8_flash_set('success', 'Document record disposed.');
        } else {
            t8_flash_set('danger', 'That record could not be disposed - it may not be a Document, or is not currently Archived.');
        }
        redirect(page_url('retention', ['action' => 'view', 'id' => $recordId]));
        break;

    case 'dispose_request':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('retention'));
        }
        $recordId = (int) ($_POST['id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '') {
            t8_flash_set('danger', 'A disposal reason is required.');
        } elseif (t8_retention_request_disposal($pdo, $recordId, $currentUserId, $reason)) {
            t8_audit_log($pdo, $currentUserId, 'retention_record', $recordId, 'disposal_requested', null, $reason);
            t8_flash_set('success', 'Disposal requested. A different administrator must authorize it.');
        } else {
            t8_flash_set('danger', 'That record is not in a state that can be requested for disposal.');
        }
        redirect(page_url('retention', ['action' => 'view', 'id' => $recordId]));
        break;

    case 'dispose_authorize':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('retention'));
        }
        $recordId = (int) ($_POST['id'] ?? 0);
        $result = t8_retention_authorize_disposal($pdo, $recordId, $currentUserId);
        if ($result['ok']) {
            t8_audit_log($pdo, $currentUserId, 'retention_record', $recordId, 'disposed');
            t8_flash_set('success', 'Record disposed.');
        } else {
            t8_flash_set('danger', (string) $result['error']);
        }
        redirect(page_url('retention', ['action' => 'view', 'id' => $recordId]));
        break;

    case 'view':
        $recordId = (int) ($_GET['id'] ?? 0);
        $record = $recordId ? t8_retention_fetch($pdo, $recordId) : null;
        if (!$record) {
            t8_flash_set('danger', 'Retention record not found.');
            redirect(page_url('retention'));
        }
        $disposalRule = t8_retention_disposal_rule((string) $record['entity_type']);
        break;
}

$showRegisterForm = $action === 'register';
$showView = $action === 'view';
$showList = !$showRegisterForm && !$showView;

if ($showList) {
    $entityTypeFilter = (string) ($_GET['entity_type'] ?? '');
    $statusFilter = ($_GET['status'] ?? 'active') === 'archived' ? 'archived' : 'active';
    $whereClause = $statusFilter === 'archived' ? "r.status = 'disposed'" : "r.status != 'disposed'";
    $params = [];
    if (in_array($entityTypeFilter, T8_RETENTION_ENTITY_TYPES, true)) {
        $whereClause .= ' AND r.entity_type = :entity_type';
        $params['entity_type'] = $entityTypeFilter;
    }

    $recordsStmt = $pdo->prepare(
        "SELECT r.*, u.full_name AS custodian_name
         FROM team8_records r
         JOIN users u ON u.id = r.custodian_id
         WHERE $whereClause
         ORDER BY r.disposition_date ASC"
    );
    $recordsStmt->execute($params);
    $records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($records as &$row) {
        $row['entity'] = t8_retention_resolve_entity($pdo, (string) $row['entity_type'], (int) $row['entity_id']);
    }
    unset($row);

    $kpiTotalDocuments = (int) $pdo->query('SELECT COUNT(*) FROM team8_documents WHERE deleted_at IS NULL')->fetchColumn();
    $kpiTotalContracts = (int) $pdo->query('SELECT COUNT(*) FROM team8_contracts WHERE deleted_at IS NULL')->fetchColumn();
    $kpiTotalLegalCases = (int) $pdo->query('SELECT COUNT(*) FROM team8_legal_cases WHERE deleted_at IS NULL')->fetchColumn();

    $kpiDueSoon90 = (int) $pdo->query(
        "SELECT COUNT(*) FROM team8_records WHERE status IN ('active', 'due_review')
         AND disposition_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)"
    )->fetchColumn();
    $kpiPendingDisposal = (int) $pdo->query("SELECT COUNT(*) FROM team8_records WHERE status = 'pending_disposal'")->fetchColumn();
    $kpiArchivedThisMonth = (int) $pdo->query(
        "SELECT COUNT(*) FROM team8_records WHERE status = 'archived'
         AND archived_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
    )->fetchColumn();

    $orphanDocuments = t8_retention_orphan_count($pdo, 'document');
    $orphanContracts = t8_retention_orphan_count($pdo, 'contract');
    $orphanLegalCases = t8_retention_orphan_count($pdo, 'legal_case');
    $totalTrackable = $kpiTotalDocuments + $kpiTotalContracts + $kpiTotalLegalCases;
    $totalOrphans = $orphanDocuments + $orphanContracts + $orphanLegalCases;
    $compliancePercent = $totalTrackable > 0 ? (int) round((($totalTrackable - $totalOrphans) / $totalTrackable) * 100) : 100;

    $unifiedActivity = $pdo->query(
        "SELECT a.action, a.entity_type, a.created_at, u.full_name
         FROM audit_logs a
         JOIN users u ON u.id = a.user_id
         WHERE a.entity_type IN ('document', 'contract', 'legal_case', 'retention_record')
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    $quickListByType = [];
    foreach (T8_RETENTION_ENTITY_TYPES as $type) {
        $rows = t8_retention_due_soon($pdo, 90, $type, 5);
        foreach ($rows as &$row) {
            $row['entity'] = t8_retention_resolve_entity($pdo, $type, (int) $row['entity_id']);
        }
        unset($row);
        $quickListByType[$type] = $rows;
    }
}
?>
<div class="t8-retention-heading">
    <div><h1>Records Retention</h1><p class="t8-help-text">Documents, Contracts, and Legal Cases under retention - how long they must be kept, when they're due for review, and how they're archived or disposed of.</p></div>
</div>

<?php foreach ($errors as $error): ?>
    <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if ($showRegisterForm): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('retention')) ?>"><i class="fa-solid fa-arrow-left"></i> Back to Retention</a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Register a Record Under Retention</h2>
        </div>
        <p class="t8-help-text">
            Contracts and Legal Cases normally register themselves automatically when they reach
            end-of-life. Use this form only for a manual/early registration, or for a Document
            that wasn't auto-registered at upload time.
        </p>

        <?php if ($candidateDocuments === [] && $candidateContracts === [] && $candidateLegalCases === []): ?>
            <div class="t8-empty">Every eligible record is already registered under retention.</div>
        <?php else: ?>
            <form method="post" action="<?= e(page_url('retention', ['action' => 'register'])) ?>" novalidate id="t8RetentionRegisterForm">
                <?= t8_csrf_field() ?>

                <div class="t8-field">
                    <label class="t8-label" for="entity_type">Record Type</label>
                    <select class="t8-select" id="entity_type" name="entity_type" required>
                        <option value="">Select a type…</option>
                        <option value="document">Document</option>
                        <option value="contract">Contract</option>
                        <option value="legal_case">Legal Case (closed only)</option>
                    </select>
                </div>

                <div class="t8-field" data-retention-entity-group="document" hidden>
                    <label class="t8-label" for="entity_id_document">Document</label>
                    <select class="t8-select t8-retention-entity-select" id="entity_id_document" data-entity-type="document" name="entity_id">
                        <option value="">Select a document…</option>
                        <?php foreach ($candidateDocuments as $d): ?>
                            <option value="<?= e((string) $d['id']) ?>"><?= e($d['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="t8-field" data-retention-entity-group="contract" hidden>
                    <label class="t8-label" for="entity_id_contract">Contract</label>
                    <select class="t8-select t8-retention-entity-select" id="entity_id_contract" data-entity-type="contract" name="entity_id">
                        <option value="">Select a contract…</option>
                        <?php foreach ($candidateContracts as $c): ?>
                            <option value="<?= e((string) $c['id']) ?>"><?= e($c['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="t8-field" data-retention-entity-group="legal_case" hidden>
                    <label class="t8-label" for="entity_id_legal_case">Legal Case</label>
                    <select class="t8-select t8-retention-entity-select" id="entity_id_legal_case" data-entity-type="legal_case" name="entity_id">
                        <option value="">Select a closed case…</option>
                        <?php foreach ($candidateLegalCases as $lc): ?>
                            <option value="<?= e((string) $lc['id']) ?>"><?= e($lc['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($candidateLegalCases === []): ?>
                        <span class="t8-help-text">No closed cases are awaiting registration - a case must be Closed before it can be retained.</span>
                    <?php endif; ?>
                </div>

                <div class="t8-field">
                    <label class="t8-label" for="retention_basis">Retention Basis</label>
                    <input class="t8-input" type="text" id="retention_basis" name="retention_basis" required placeholder="e.g. BIR RR No. 7-2024 (EOPT Act)">
                    <span class="t8-help-text" id="t8RetentionSuggestions"></span>
                </div>
                <div class="t8-field">
                    <label class="t8-label" for="retention_years">Retention Period (years)</label>
                    <input class="t8-input" type="number" id="retention_years" name="retention_years" min="1" required>
                </div>
                <div class="t8-field">
                    <label class="t8-label" for="retention_start_date">Retention Clock Start</label>
                    <input class="t8-input" type="date" id="retention_start_date" name="retention_start_date" value="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div class="t8-field">
                    <label class="t8-label" for="custodian_id">Custodian</label>
                    <select class="t8-select" id="custodian_id" name="custodian_id">
                        <?php foreach ($custodianOptions as $custodian): ?>
                            <option value="<?= e((string) $custodian['id']) ?>" <?= (int) $custodian['id'] === $currentUserId ? 'selected' : '' ?>><?= e($custodian['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-check"></i> Register</button>
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('retention')) ?>">Cancel</a>
            </form>

            <script>
            (function () {
                var suggestions = <?= json_encode(T8_RETENTION_SUGGESTED_BASES, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                var typeSelect = document.getElementById('entity_type');
                var basisInput = document.getElementById('retention_basis');
                var yearsInput = document.getElementById('retention_years');
                var suggestionText = document.getElementById('t8RetentionSuggestions');
                var groups = document.querySelectorAll('[data-retention-entity-group]');

                function applyType() {
                    var type = typeSelect.value;
                    groups.forEach(function (g) {
                        var match = g.getAttribute('data-retention-entity-group') === type;
                        g.hidden = !match;
                        var select = g.querySelector('.t8-retention-entity-select');
                        if (select) select.disabled = !match;
                    });

                    var list = suggestions[type] || [];
                    if (list.length) {
                        basisInput.value = list[0].basis;
                        yearsInput.value = list[0].years;
                        suggestionText.textContent = 'Suggested: ' + list.map(function (s) { return s.basis + ' (' + s.years + ' yrs)'; }).join(' · ');
                    } else {
                        suggestionText.textContent = '';
                    }
                }

                typeSelect.addEventListener('change', applyType);
                applyType();
            })();
            </script>
        <?php endif; ?>
    </div>

<?php elseif ($showView): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('retention')) ?>"><i class="fa-solid fa-arrow-left"></i> Back to Retention</a>
        <?php if ($record['entity'] !== null): ?>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url((string) $record['entity']['view_action']['page'], array_diff_key($record['entity']['view_action'], ['page' => '']))) ?>">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Open <?= e($entityTypeLabels[$record['entity_type']]) ?>
            </a>
        <?php endif; ?>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= e($entityTypeLabels[$record['entity_type']] ?? $record['entity_type']) ?> — <?= e($record['entity']['label'] ?? 'Record not found') ?></h2>
            <span class="t8-badge <?= e(t8_retention_status_badge((string) $record['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $record['status']))) ?></span>
        </div>

        <?php if ($record['entity'] === null): ?>
            <div class="t8-alert t8-alert-warning">The underlying <?= e(strtolower($entityTypeLabels[$record['entity_type']])) ?> record no longer exists. This retention record is orphaned - review before disposing of it.</div>
        <?php endif; ?>

        <div class="t8-hr-readonly-block">
            <div class="t8-hr-readonly-item"><span>Retention Basis</span><strong><?= e((string) $record['retention_basis']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Retention Period</span><strong><?= e((string) $record['retention_years']) ?> years</strong></div>
            <div class="t8-hr-readonly-item"><span>Clock Start</span><strong><?= e(format_date((string) $record['retention_start_date'], 'M d, Y')) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Disposition Date</span><strong><?= e(format_date((string) $record['disposition_date'], 'M d, Y')) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Custodian</span><strong><?= e((string) $record['custodian_name']) ?></strong></div>
            <?php if ($record['entity'] !== null && !empty($record['entity']['related_name'])): ?>
                <div class="t8-hr-readonly-item"><span><?= e((string) $record['entity']['related_role']) ?></span><strong><?= e((string) $record['entity']['related_name']) ?></strong></div>
            <?php endif; ?>
        </div>

        <?php if (!$isAdmin): ?>
            <p class="t8-help-text">Only an administrator can archive, request, or authorize disposal.</p>

        <?php elseif (in_array($record['status'], ['active', 'due_review'], true)): ?>
            <form method="post" action="<?= e(page_url('retention', ['action' => 'archive'])) ?>" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
                <?= t8_csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string) $record['id']) ?>">
                <div class="t8-field" style="margin-bottom:0; flex:1; min-width:220px;">
                    <label class="t8-label" for="archive_reason">Archive Reason</label>
                    <input class="t8-input" type="text" id="archive_reason" name="reason" required>
                </div>
                <button class="t8-btn t8-btn-outline" type="submit"><i class="fa-solid fa-box-archive"></i> Archive</button>
            </form>

        <?php elseif ($record['status'] === 'archived' && $disposalRule['two_step'] === false): ?>
            <div class="t8-alert t8-alert-warning">Disposing a Document record is immediate and cannot be undone.</div>
            <form method="post" action="<?= e(page_url('retention', ['action' => 'dispose'])) ?>" onsubmit="return confirm('Dispose of this document record? This cannot be undone.');" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
                <?= t8_csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string) $record['id']) ?>">
                <div class="t8-field" style="margin-bottom:0; flex:1; min-width:220px;">
                    <label class="t8-label" for="dispose_reason">Disposal Reason</label>
                    <input class="t8-input" type="text" id="dispose_reason" name="reason" required>
                </div>
                <button class="t8-btn t8-btn-danger" type="submit"><i class="fa-solid fa-trash"></i> Dispose</button>
            </form>

        <?php elseif ($record['status'] === 'archived' && $disposalRule['two_step'] === true): ?>
            <div class="t8-alert t8-alert-info">
                <?= $disposalRule['dual_control']
                    ? 'Requesting disposal does not dispose the record - a <strong>different</strong> administrator must authorize it below given this record\'s legal significance.'
                    : 'Requesting disposal does not dispose the record - a second confirmation step is required before it is actually disposed.' ?>
            </div>
            <form method="post" action="<?= e(page_url('retention', ['action' => 'dispose_request'])) ?>" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
                <?= t8_csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string) $record['id']) ?>">
                <div class="t8-field" style="margin-bottom:0; flex:1; min-width:220px;">
                    <label class="t8-label" for="dispose_reason">Disposal Reason</label>
                    <input class="t8-input" type="text" id="dispose_reason" name="reason" required>
                </div>
                <button class="t8-btn t8-btn-danger" type="submit"><i class="fa-solid fa-trash"></i> Request Disposal</button>
            </form>

        <?php elseif ($record['status'] === 'pending_disposal'): ?>
            <?php $isSameAdmin = (int) $record['disposal_requested_by'] === $currentUserId; ?>
            <div class="t8-hr-readonly-block">
                <div class="t8-hr-readonly-item"><span>Requested By</span><strong><?= e((string) $record['disposal_requested_by_name']) ?></strong></div>
                <div class="t8-hr-readonly-item"><span>Requested At</span><strong><?= e(format_date((string) $record['disposal_requested_at'], 'M d, Y g:i A')) ?></strong></div>
                <div class="t8-hr-readonly-item"><span>Reason</span><strong><?= e((string) $record['disposal_reason']) ?></strong></div>
            </div>
            <?php if ($disposalRule['dual_control'] && $isSameAdmin): ?>
                <div class="t8-alert t8-alert-warning">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    You requested this disposal. A <strong>different</strong> administrator must authorize it.
                </div>
                <button class="t8-btn t8-btn-danger" type="button" disabled title="A different administrator must authorize this">
                    <i class="fa-solid fa-lock"></i> Authorize Disposal (unavailable to you)
                </button>
            <?php else: ?>
                <form method="post" action="<?= e(page_url('retention', ['action' => 'dispose_authorize'])) ?>" onsubmit="return confirm('Authorize disposal of this record? This cannot be undone.');">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $record['id']) ?>">
                    <button class="t8-btn t8-btn-danger" type="submit"><i class="fa-solid fa-check-double"></i> Authorize Disposal</button>
                </form>
            <?php endif; ?>

        <?php elseif ($record['status'] === 'disposed'): ?>
            <div class="t8-hr-readonly-block">
                <div class="t8-hr-readonly-item"><span>Disposed At</span><strong><?= e(format_date((string) $record['disposed_at'], 'M d, Y g:i A')) ?></strong></div>
                <?php if ($record['disposal_authorized_by_name']): ?>
                    <div class="t8-hr-readonly-item"><span>Authorized By</span><strong><?= e((string) $record['disposal_authorized_by_name']) ?></strong></div>
                <?php endif; ?>
                <div class="t8-hr-readonly-item"><span>Reason</span><strong><?= e((string) ($record['disposal_reason'] ?? '—')) ?></strong></div>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>

    <!-- =====================================================
         PHASE 5 — UNIFIED DASHBOARD
         ===================================================== -->

    <div class="t8-retention-kpi-grid" aria-label="Unified retention dashboard">
        <a class="t8-retention-kpi" href="<?= e(page_url('documents', ['action' => 'browse'])) ?>">
            <div class="t8-retention-kpi-icon"><i class="fa-solid fa-file-lines"></i></div>
            <div><span>Total Documents</span><strong><?= e((string) $kpiTotalDocuments) ?></strong><small>Active uploads</small></div>
        </a>
        <a class="t8-retention-kpi" href="<?= e(page_url('contracts')) ?>">
            <div class="t8-retention-kpi-icon"><i class="fa-solid fa-file-contract"></i></div>
            <div><span>Total Contracts</span><strong><?= e((string) $kpiTotalContracts) ?></strong><small>Across all lifecycle stages</small></div>
        </a>
        <a class="t8-retention-kpi" href="<?= e(page_url('legal')) ?>">
            <div class="t8-retention-kpi-icon"><i class="fa-solid fa-scale-balanced"></i></div>
            <div><span>Total Legal Cases</span><strong><?= e((string) $kpiTotalLegalCases) ?></strong><small>Open, monitored &amp; closed</small></div>
        </a>
        <div class="t8-retention-kpi t8-retention-kpi-orange">
            <div class="t8-retention-kpi-icon"><i class="fa-solid fa-clock"></i></div>
            <div><span>Due for Review</span><strong><?= e((string) $kpiDueSoon90) ?></strong><small>Within 90 days, all types</small></div>
        </div>
        <div class="t8-retention-kpi t8-retention-kpi-orange">
            <div class="t8-retention-kpi-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <div><span>Pending Disposal</span><strong><?= e((string) $kpiPendingDisposal) ?></strong><small>Awaiting authorization</small></div>
        </div>
        <div class="t8-retention-kpi t8-retention-kpi-gray">
            <div class="t8-retention-kpi-icon"><i class="fa-solid fa-box-archive"></i></div>
            <div><span>Archived This Month</span><strong><?= e((string) $kpiArchivedThisMonth) ?></strong><small>Since <?= e(date('M 1')) ?></small></div>
        </div>
    </div>

    <?php if ($isAdmin): ?>
        <div class="t8-retention-summary-grid">
            <div class="t8-card t8-retention-ending-card">
                <div class="t8-card-header"><h2 class="t8-card-title"><i class="fa-solid fa-list-check"></i> Recent Activity</h2></div>
                <?php if ($unifiedActivity === []): ?>
                    <div class="t8-retention-empty"><i class="fa-regular fa-face-smile"></i><span>No document, contract, or legal case activity recorded yet.</span></div>
                <?php else: ?>
                    <?php foreach ($unifiedActivity as $activity): ?>
                        <div class="t8-retention-deadline">
                            <span class="t8-retention-severity"></span>
                            <strong><?= e($activity['full_name']) ?> <?= e(t8_retention_activity_label((string) $activity['action'], (string) $activity['entity_type'])) ?></strong>
                            <span><?= e(format_date((string) $activity['created_at'], 'M d, g:i A')) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="t8-card t8-compliance-card">
                <div class="t8-card-header"><h2 class="t8-card-title"><i class="fa-solid fa-chart-pie"></i> Retention Compliance</h2><a class="t8-card-link" href="#retention-records">View Records <span aria-hidden="true">→</span></a></div>
                <div class="t8-compliance-score"><strong><?= e((string) $compliancePercent) ?>%</strong><span>of records registered</span></div>
                <div class="t8-compliance-bar"><span style="width:<?= e((string) $compliancePercent) ?>%"></span></div>
                <div class="t8-table-wrap" style="margin-top:14px; border:0;">
                    <table class="t8-table">
                        <thead><tr><th>Type</th><th>Not Registered</th></tr></thead>
                        <tbody>
                            <tr><td>Documents</td><td><?= $orphanDocuments > 0 ? '<span class="t8-badge t8-badge-pending">' . e((string) $orphanDocuments) . '</span>' : '<span class="t8-badge t8-badge-approved">0</span>' ?></td></tr>
                            <tr><td>Contracts</td><td><?= $orphanContracts > 0 ? '<span class="t8-badge t8-badge-pending">' . e((string) $orphanContracts) . '</span>' : '<span class="t8-badge t8-badge-approved">0</span>' ?></td></tr>
                            <tr><td>Legal Cases (closed)</td><td><?= $orphanLegalCases > 0 ? '<span class="t8-badge t8-badge-pending">' . e((string) $orphanLegalCases) . '</span>' : '<span class="t8-badge t8-badge-approved">0</span>' ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="t8-retention-summary-grid" style="grid-template-columns: repeat(3, minmax(0, 1fr));">
            <?php foreach (T8_RETENTION_ENTITY_TYPES as $type): ?>
                <div class="t8-card">
                    <div class="t8-card-header"><h2 class="t8-card-title"><?= e($entityTypeLabels[$type]) ?> — Coming Up</h2></div>
                    <?php if ($quickListByType[$type] === []): ?>
                        <div class="t8-retention-empty"><i class="fa-regular fa-calendar-check"></i><span>Nothing due in the next 90 days.</span></div>
                    <?php else: ?>
                        <?php foreach ($quickListByType[$type] as $item): ?>
                            <?php $itemIsOverdue = strtotime((string) $item['disposition_date']) < strtotime('today'); ?>
                            <a class="t8-retention-deadline" href="<?= e(page_url('retention', ['action' => 'view', 'id' => $item['id']])) ?>" style="text-decoration:none;">
                                <span class="t8-retention-severity <?= $itemIsOverdue ? 'is-critical' : 'is-warning' ?>"></span>
                                <strong><?= e($item['entity']['label'] ?? '(record no longer exists)') ?></strong>
                                <span><?= e(format_date((string) $item['disposition_date'], 'M d, Y')) ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <?php if ($isAdmin): ?>
            <a class="t8-btn t8-btn-accent" href="<?= e(page_url('retention', ['action' => 'register'])) ?>">
                <i class="fa-solid fa-plus"></i> Register a Record
            </a>
        <?php endif; ?>
        <?php if ($statusFilter === 'active'): ?>
            <a id="t8RetentionToggle" class="t8-btn t8-btn-outline" href="<?= e(page_url('retention', ['status' => 'archived'])) ?>">
                <i class="fa-solid fa-box-archive"></i> View Disposed
            </a>
        <?php else: ?>
            <a id="t8RetentionToggle" class="t8-btn t8-btn-outline" href="<?= e(page_url('retention')) ?>">
                <i class="fa-solid fa-list"></i> View Active
            </a>
        <?php endif; ?>
        <form method="get" action="<?= e(base_url('index.php')) ?>" style="display:inline-flex; gap:8px;">
            <input type="hidden" name="page" value="retention">
            <?php if ($statusFilter === 'archived'): ?><input type="hidden" name="status" value="archived"><?php endif; ?>
            <select class="t8-select" name="entity_type" onchange="this.form.submit()">
                <option value="">All record types</option>
                <?php foreach ($entityTypeLabels as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $entityTypeFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div id="t8RetentionTableShell">
        <div class="t8-card" id="retention-records">
            <div class="t8-card-header">
                <h2 class="t8-card-title"><?= $statusFilter === 'archived' ? 'Disposed Records' : 'Records Under Retention' ?></h2>
            </div>
            <?php if ($records === []): ?>
                <div class="t8-empty">
                    <?= $statusFilter === 'archived' ? 'No records have been disposed yet.' : 'No records under retention yet.' ?>
                </div>
            <?php else: ?>
                <div class="t8-table-wrap">
                    <table class="t8-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Record</th>
                                <th>Custodian</th>
                                <th>Disposition Date</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $r): ?>
                                <?php $isOverdue = in_array($r['status'], ['active', 'due_review'], true) && strtotime((string) $r['disposition_date']) < strtotime('today'); ?>
                                <tr>
                                    <td><span class="t8-type-pill"><?= e($entityTypeLabels[$r['entity_type']] ?? $r['entity_type']) ?></span></td>
                                    <td><?= e($r['entity']['label'] ?? '(record no longer exists)') ?></td>
                                    <td><?= e($r['custodian_name']) ?></td>
                                    <td><?= e(format_date($r['disposition_date'], 'M d, Y')) ?></td>
                                    <td>
                                        <?php if ($isOverdue): ?>
                                            <span class="t8-badge t8-badge-pending"><i class="fa-solid fa-triangle-exclamation"></i> Overdue</span>
                                        <?php else: ?>
                                            <span class="t8-badge <?= e(t8_retention_status_badge((string) $r['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $r['status']))) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('retention', ['action' => 'view', 'id' => $r['id']])) ?>">
                                            <i class="fa-solid fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>
