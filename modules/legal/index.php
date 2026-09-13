<?php
/**
 * modules/legal/index.php
 * Legal Management - administrators manage cases; assigned Legal Officers
 * have read-only access only to their own cases.
 *
 * PHASE 3 (Document/Legal/Contract/Retention rebuild):
 *   - Legal Cases get the same computed "monitoring" sub-state as
 *     Contracts (Phase 2), driven off the existing `deadline` column:
 *     an open/under_review case within 90 days of its deadline shows
 *     a Monitoring badge, exactly mirroring
 *     t8_contract_is_monitoring() in modules/contracts/index.php.
 *   - A case is registered under retention automatically the moment
 *     it first reaches 'closed' status, anchored to the new
 *     `closed_at` timestamp (see
 *     database/migrations/2026_09_11_legal_case_closed_at.sql -
 *     updated_at was not safe to use, since it changes on every edit,
 *     not only the closure event).
 *   - Legal Case disposal is DUAL CONTROL: the confirmed plan requires
 *     the requester and the authorizer to be two different
 *     administrators, given the case's evidentiary/legal significance.
 *     This is enforced in app/includes/retention_helpers.php's
 *     t8_retention_authorize_disposal() (Phase 1) - server-side, not
 *     only in this file's UI. This file's job is to expose that
 *     workflow clearly and disable the "wrong" button as a courtesy,
 *     not as the actual control.
 *
 * Backing tables:
 *   team8_legal_cases     (id, assigned_to, contract_id, title, status,
 *     filed_date, deadline, closed_at, created_at, updated_at, deleted_at)
 *   team8_legal_documents (id, case_id, document_id, description,
 *     created_at) - links an existing Document Management document to
 *     a case; attaching/removing here never touches the document
 *     itself, only the link row.
 *   team8_records         (polymorphic retention table - Phase 1)
 *
 * contract_id is nullable and intentionally left unset by this form
 * for now - it gets wired up once Contract Management exists.
 *
 * Access: Administrator only for mutations. Legal Officers see only
 * their own assigned cases, read-only plus document attachment.
 */

declare(strict_types=1);

// Retention registration + disposal workflow hook (Phase 1/3 of the
// Document/Legal/Contract/Retention rebuild) - defensive require,
// same pattern as ai_helper.php's require in documents/index.php.
$retentionHelperPath = __DIR__ . '/../../app/includes/retention_helpers.php';
if (is_file($retentionHelperPath)) {
    require_once $retentionHelperPath;
}

t8_require_role(['admin', 'legal_officer']);

$pageTitle = 'Legal Management';
$currentUserId = t8_current_user_id();
$isAdmin = t8_has_role('admin');
$action = $_GET['action'] ?? 'list';
$errors = [];

const T8_LEGAL_STATUSES = ['open', 'under_review', 'resolved', 'closed'];

/** Allow the module to remain readable until its additive migration is run. */
function t8_legal_has_case_metadata(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW COLUMNS FROM team8_legal_cases LIKE 'department_id'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

/** Guards against modules/legal/index.php running before the Phase 3 migration lands. */
function t8_legal_has_closed_at(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW COLUMNS FROM team8_legal_cases LIKE 'closed_at'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

$legalHasCaseMetadata = t8_legal_has_case_metadata($pdo);
$legalHasClosedAt = t8_legal_has_closed_at($pdo);

/** Fetch one legal case with assignee name, or null. */
function t8_legal_case_fetch(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT lc.*, u.full_name AS assigned_to_name
         FROM team8_legal_cases lc
         JOIN users u ON u.id = lc.assigned_to
         WHERE lc.id = :id' . (t8_has_role('admin') ? '' : ' AND lc.assigned_to = :assigned_to') . ' LIMIT 1'
    );
    $params = ['id' => $id];
    if (!t8_has_role('admin')) { $params['assigned_to'] = t8_current_user_id(); }
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * MONITORING (computed, not stored) - mirrors
 * t8_contract_is_monitoring() in modules/contracts/index.php exactly:
 * an open/under_review case within $withinDays of its deadline shows
 * the same visual sub-badge treatment, driven off a real date instead
 * of a second stored status that could drift out of sync.
 */
function t8_legal_is_monitoring(array $case, int $withinDays = 90): bool
{
    if (!in_array($case['status'] ?? '', ['open', 'under_review'], true)) {
        return false;
    }
    $deadline = $case['deadline'] ?? null;
    if ($deadline === null || $deadline === '') {
        return false;
    }
    $ts = strtotime((string) $deadline);
    return $ts !== false && $ts <= strtotime("+{$withinDays} days");
}

/**
 * Registers a newly-closed case under retention EXACTLY ONCE - checks
 * t8_retention_fetch_for_entity() first, same guard as
 * t8_contract_register_retention() in modules/contracts/index.php, so
 * a records officer's later manual override of retention_years/basis
 * is never silently clobbered by a subsequent edit to the case.
 */
function t8_legal_register_retention(PDO $pdo, array $case, int $actorId): void
{
    if (!function_exists('t8_retention_register') || !function_exists('t8_retention_fetch_for_entity')) {
        return; // retention_helpers.php not present yet (Phase 1 not deployed)
    }
    if (t8_retention_fetch_for_entity($pdo, 'legal_case', (int) $case['id']) !== null) {
        return; // already registered - never overwrite a manual override
    }

    $clockStart = $case['closed_at'] ?? date('Y-m-d H:i:s');
    t8_retention_register(
        $pdo,
        'legal_case',
        (int) $case['id'],
        'General civil prescription period (Civil Code Arts. 1139-1155) + records retention practice',
        10,
        (string) $clockStart,
        $actorId
    );
    t8_audit_log($pdo, $actorId, 'legal_case', (int) $case['id'], 'retention_registered');
}

$assignees = $pdo->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC);
$departments = $legalHasCaseMetadata ? $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) : [];

switch ($action) {
    case 'create':
    case 'edit':
        t8_require_role(['admin']);
        $caseId = $action === 'edit' ? (int) ($_GET['id'] ?? 0) : 0;
        $existing = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;
        if ($action === 'edit' && !$existing) {
            t8_flash_set('danger', 'Legal case not found.');
            redirect(page_url('legal'));
        }

        $formValues = $existing !== null
            ? [
                'title'       => $existing['title'],
                'subject'     => (string) ($existing['subject'] ?? ''),
                'department_id' => (string) ($existing['department_id'] ?? ''),
                'status'      => $existing['status'],
                'filed_date'  => $existing['filed_date'],
                'deadline'    => (string) ($existing['deadline'] ?? ''),
                'assigned_to' => (string) $existing['assigned_to'],
            ]
            : ['title' => '', 'subject' => '', 'department_id' => '', 'status' => 'open', 'filed_date' => date('Y-m-d'), 'deadline' => '', 'assigned_to' => (string) $currentUserId];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $formValues = [
                'title'       => trim((string) ($_POST['title'] ?? '')),
                'subject'     => trim((string) ($_POST['subject'] ?? '')),
                'department_id' => (string) ($_POST['department_id'] ?? ''),
                'status'      => (string) ($_POST['status'] ?? 'open'),
                'filed_date'  => trim((string) ($_POST['filed_date'] ?? '')),
                'deadline'    => trim((string) ($_POST['deadline'] ?? '')),
                'assigned_to' => (string) ($_POST['assigned_to'] ?? ''),
            ];

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } else {
                if ($formValues['title'] === '') {
                    $errors[] = 'Case title is required.';
                }
                if (!in_array($formValues['status'], T8_LEGAL_STATUSES, true)) {
                    $errors[] = 'Invalid status selected.';
                }
                if ($formValues['filed_date'] === '' || strtotime($formValues['filed_date']) === false) {
                    $errors[] = 'Filed date must be a valid date.';
                }
                if ($formValues['deadline'] !== '' && strtotime($formValues['deadline']) === false) { $errors[] = 'Deadline must be a valid date.'; }
                if (!$formValues['assigned_to']) {
                    $errors[] = 'Please assign this case to someone.';
                }

                if (!$errors) {
                    // Was this case ALREADY closed before this save? Only the
                    // FIRST transition into 'closed' sets closed_at and fires
                    // retention registration - re-saving an already-closed
                    // case (e.g. fixing a typo in the title) must not reset
                    // the retention clock.
                    $wasAlreadyClosed = $existing !== null && $existing['status'] === 'closed';
                    $justClosed = $legalHasClosedAt && $formValues['status'] === 'closed' && !$wasAlreadyClosed;

                    if ($action === 'create') {
                        $sql = $legalHasCaseMetadata
                            ? 'INSERT INTO team8_legal_cases (assigned_to, title, subject, department_id, status, filed_date, deadline' . ($justClosed ? ', closed_at' : '') . ') VALUES (:assigned_to, :title, :subject, :department_id, :status, :filed_date, :deadline' . ($justClosed ? ', NOW()' : '') . ')'
                            : 'INSERT INTO team8_legal_cases (assigned_to, title, status, filed_date' . ($justClosed ? ', closed_at' : '') . ') VALUES (:assigned_to, :title, :status, :filed_date' . ($justClosed ? ', NOW()' : '') . ')';
                        $params = [
                            'assigned_to' => (int) $formValues['assigned_to'],
                            'title'       => $formValues['title'],
                            'status'      => $formValues['status'],
                            'filed_date'  => $formValues['filed_date'],
                        ];
                        if ($legalHasCaseMetadata) {
                            $params += ['subject' => $formValues['subject'] !== '' ? $formValues['subject'] : null, 'department_id' => $formValues['department_id'] !== '' ? (int) $formValues['department_id'] : null, 'deadline' => $formValues['deadline'] !== '' ? $formValues['deadline'] : null];
                        }
                        $pdo->prepare($sql)->execute($params);
                        $newId = (int) $pdo->lastInsertId();
                        t8_audit_log($pdo, $currentUserId, 'legal_case', $newId, 'create');
                        t8_flash_set('success', 'Legal case created.');

                        if ($justClosed) {
                            $created = t8_legal_case_fetch($pdo, $newId);
                            if ($created !== null) {
                                t8_legal_register_retention($pdo, $created, $currentUserId);
                            }
                        }
                    } else {
                        $sql = $legalHasCaseMetadata
                            ? 'UPDATE team8_legal_cases SET assigned_to = :assigned_to, title = :title, subject = :subject, department_id = :department_id, status = :status, filed_date = :filed_date, deadline = :deadline' . ($justClosed ? ', closed_at = NOW()' : '') . ' WHERE id = :id'
                            : 'UPDATE team8_legal_cases SET assigned_to = :assigned_to, title = :title, status = :status, filed_date = :filed_date' . ($justClosed ? ', closed_at = NOW()' : '') . ' WHERE id = :id';
                        $params = [
                            'assigned_to' => (int) $formValues['assigned_to'],
                            'title'       => $formValues['title'],
                            'status'      => $formValues['status'],
                            'filed_date'  => $formValues['filed_date'],
                            'id'          => $caseId,
                        ];
                        if ($legalHasCaseMetadata) {
                            $params += ['subject' => $formValues['subject'] !== '' ? $formValues['subject'] : null, 'department_id' => $formValues['department_id'] !== '' ? (int) $formValues['department_id'] : null, 'deadline' => $formValues['deadline'] !== '' ? $formValues['deadline'] : null];
                        }
                        $pdo->prepare($sql)->execute($params);
                        t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'update');
                        t8_flash_set('success', 'Legal case updated.');

                        if ($justClosed) {
                            $refreshed = t8_legal_case_fetch($pdo, $caseId);
                            if ($refreshed !== null) {
                                t8_legal_register_retention($pdo, $refreshed, $currentUserId);
                            }
                        }
                    }
                    redirect(page_url('legal'));
                }
            }
        }
        break;

    case 'archive':
    case 'restore':
        t8_require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('legal'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('legal'));
        }
        $id = (int) ($_POST['id'] ?? 0);
        if (t8_legal_case_fetch($pdo, $id)) {
            $sql = $action === 'archive'
                ? 'UPDATE team8_legal_cases SET deleted_at = NOW() WHERE id = :id'
                : 'UPDATE team8_legal_cases SET deleted_at = NULL WHERE id = :id';
            $pdo->prepare($sql)->execute(['id' => $id]);
            t8_audit_log($pdo, $currentUserId, 'legal_case', $id, $action);
            t8_flash_set('success', $action === 'archive' ? 'Case archived.' : 'Case restored.');
        } else {
            t8_flash_set('danger', 'Legal case not found.');
        }
        redirect(page_url('legal'));
        break;

    case 'documents':
        $caseId = (int) ($_GET['id'] ?? 0);
        $case = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;
        if (!$case) {
            t8_flash_set('danger', 'Legal case not found.');
            redirect(page_url('legal'));
        }
        t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'view_documents');

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'attach') {
            t8_require_role(['admin']);
            $documentId = (int) ($_POST['document_id'] ?? 0);
            $description = trim((string) ($_POST['description'] ?? ''));

            if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
                $errors[] = 'Your session expired. Please try again.';
            } elseif (!$documentId) {
                $errors[] = 'Please select a document to attach.';
            } else {
                $pdo->prepare(
                    'INSERT INTO team8_legal_documents (case_id, document_id, description) VALUES (:case_id, :document_id, :description)'
                )->execute([
                    'case_id'     => $caseId,
                    'document_id' => $documentId,
                    'description' => $description !== '' ? $description : null,
                ]);
                t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'attach_document');
                t8_flash_set('success', 'Document attached to case.');
                redirect(page_url('legal', ['action' => 'documents', 'id' => $caseId]));
            }
        }

        $attachedDocs = $pdo->prepare(
            'SELECT ld.*, d.title AS document_title, v.id AS version_id
             FROM team8_legal_documents ld
             JOIN team8_documents d ON d.id = ld.document_id
             JOIN team8_document_versions v ON v.document_id = d.id AND v.version_no = d.current_version
             WHERE ld.case_id = :case_id
             ORDER BY ld.created_at DESC'
        );
        $attachedDocs->execute(['case_id' => $caseId]);
        $attachedDocs = $attachedDocs->fetchAll(PDO::FETCH_ASSOC);

        $availableDocs = $pdo->query(
            'SELECT id, title FROM team8_documents WHERE deleted_at IS NULL ORDER BY title'
        )->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'detach_document':
        t8_require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            redirect(page_url('legal'));
        }
        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('legal'));
        }
        $linkId = (int) ($_POST['link_id'] ?? 0);
        $caseId = (int) ($_POST['case_id'] ?? 0);
        $pdo->prepare('DELETE FROM team8_legal_documents WHERE id = :id AND case_id = :case_id')
            ->execute(['id' => $linkId, 'case_id' => $caseId]);
        t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'detach_document');
        t8_flash_set('success', 'Document removed from case.');
        redirect(page_url('legal', ['action' => 'documents', 'id' => $caseId]));
        break;

    // ---------------------------------------------------------------
    // PHASE 3 — Retention & disposal sub-view.
    //
    // Deliberately its own screen (like 'documents' above) rather than
    // buttons crammed into the row menu - a dual-control disposal
    // action deserves its own confirmation surface, not a one-click
    // dropdown item.
    // ---------------------------------------------------------------
    case 'retention':
        $caseId = (int) ($_GET['id'] ?? 0);
        $case = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;
        if (!$case) {
            t8_flash_set('danger', 'Legal case not found.');
            redirect(page_url('legal'));
        }
        $retentionRecord = function_exists('t8_retention_fetch_for_entity')
            ? t8_retention_fetch_for_entity($pdo, 'legal_case', $caseId)
            : null;
        break;

    case 'retention_archive':
        t8_require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('legal'));
        }
        $recordId = (int) ($_POST['record_id'] ?? 0);
        $caseId = (int) ($_POST['case_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '' || !function_exists('t8_retention_archive')) {
            t8_flash_set('danger', 'An archive reason is required.');
        } elseif (t8_retention_archive($pdo, $recordId, $reason)) {
            t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'retention_archived', null, $reason);
            t8_flash_set('success', 'Retention record archived.');
        } else {
            t8_flash_set('danger', 'That retention record could not be archived from its current state.');
        }
        redirect(page_url('legal', ['action' => 'retention', 'id' => $caseId]));
        break;

    case 'retention_dispose_request':
        t8_require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('legal'));
        }
        $recordId = (int) ($_POST['record_id'] ?? 0);
        $caseId = (int) ($_POST['case_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '' || !function_exists('t8_retention_request_disposal')) {
            t8_flash_set('danger', 'A disposal reason is required.');
        } elseif (t8_retention_request_disposal($pdo, $recordId, $currentUserId, $reason)) {
            t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'disposal_requested', null, $reason);
            t8_flash_set('success', 'Disposal requested. A DIFFERENT administrator must authorize it before it is disposed.');
        } else {
            t8_flash_set('danger', 'That retention record is not in a state that can be requested for disposal.');
        }
        redirect(page_url('legal', ['action' => 'retention', 'id' => $caseId]));
        break;

    case 'retention_dispose_authorize':
        t8_require_role(['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            t8_flash_set('danger', 'Your session expired. Please try again.');
            redirect(page_url('legal'));
        }
        $recordId = (int) ($_POST['record_id'] ?? 0);
        $caseId = (int) ($_POST['case_id'] ?? 0);
        if (!function_exists('t8_retention_authorize_disposal')) {
            t8_flash_set('danger', 'Retention features are not available yet.');
            redirect(page_url('legal', ['action' => 'retention', 'id' => $caseId]));
        }
        // DUAL CONTROL: t8_retention_authorize_disposal() itself rejects
        // this if $currentUserId === the row's disposal_requested_by -
        // that check happens server-side inside the helper, not here.
        // This call site does not (and must not) attempt to duplicate
        // or second-guess that check.
        $result = t8_retention_authorize_disposal($pdo, $recordId, $currentUserId);
        if ($result['ok']) {
            t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'disposed');
            t8_flash_set('success', 'Legal case record disposed.');
        } else {
            t8_flash_set('danger', (string) $result['error']);
        }
        redirect(page_url('legal', ['action' => 'retention', 'id' => $caseId]));
        break;
}

$showForm = in_array($action, ['create', 'edit'], true);
$showDocuments = $action === 'documents';
$showRetention = $action === 'retention';
$showList = !$showForm && !$showDocuments && !$showRetention;

if ($showList) {
    $statusFilter = $_GET['status'] ?? 'all';
    $archivedFilter = ($_GET['archived'] ?? '0') === '1';
    $where = $archivedFilter ? 'lc.deleted_at IS NOT NULL' : 'lc.deleted_at IS NULL';
    $params = [];
    if (in_array($statusFilter, T8_LEGAL_STATUSES, true)) {
        $where .= ' AND lc.status = :status';
        $params['status'] = $statusFilter;
    }
    if (!$isAdmin) { $where .= ' AND lc.assigned_to = :assigned_to'; $params['assigned_to'] = $currentUserId; }
    $stmt = $pdo->prepare(
        "SELECT lc.*, u.full_name AS assigned_to_name" . ($legalHasCaseMetadata ? ', dep.name AS department_name' : '') . "
         FROM team8_legal_cases lc
         JOIN users u ON u.id = lc.assigned_to
         " . ($legalHasCaseMetadata ? 'LEFT JOIN departments dep ON dep.id = lc.department_id' : '') . "
         WHERE $where
         ORDER BY lc.filed_date DESC"
    );
    $stmt->execute($params);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function t8_legal_status_badge(string $status): string
{
    $map = [
        'open'        => 't8-badge-pending',
        'under_review' => 't8-badge-pending',
        'resolved'     => 't8-badge-approved',
        'closed'       => 't8-badge-archived',
    ];
    return $map[$status] ?? 't8-badge-pending';
}

/**
 * Renders the meatball trigger + dropdown menu for the "Legal Cases"
 * list table, plus the data-* attributes consumed by the shared
 * #t8LegalDetailModal. Same pattern as
 * t8_reservation_render_menu() in modules/reservation/index.php.
 */
function t8_legal_render_menu(array $c, bool $isAdmin, bool $archivedFilter, bool $legalHasCaseMetadata, bool $isMonitoring, ?array $retentionRecord): void
{
    $id = (int) $c['id'];
    $ref = 'CASE-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    ?>
    <div class="t8-row-menu">
        <button type="button" class="t8-row-menu-trigger" aria-haspopup="true" aria-expanded="false" title="More actions"
                data-detail-modal="t8LegalDetailModal"
                data-ref="<?= e($ref) ?>"
                data-title="<?= e((string) $c['title']) ?>"
                data-subject="<?= e((string) ($c['subject'] ?? '')) ?>"
                data-department="<?= e((string) ($c['department_name'] ?? '')) ?>"
                data-status="<?= e(ucwords(str_replace('_', ' ', (string) $c['status']))) ?><?= $isMonitoring ? ' · Monitoring' : '' ?>"
                data-filed="<?= e(format_date((string) $c['filed_date'], 'M d, Y')) ?>"
                data-deadline="<?= e($legalHasCaseMetadata && !empty($c['deadline']) ? format_date((string) $c['deadline'], 'M d, Y') : '') ?>"
                data-assigned-to="<?= e((string) $c['assigned_to_name']) ?>">
            <i class="fa-solid fa-ellipsis-vertical"></i>
        </button>
        <div class="t8-row-menu-panel" role="menu">
            <button type="button" class="t8-row-menu-item t8-row-view-details" role="menuitem">
                <i class="fa-solid fa-eye"></i> View Details
            </button>
            <button type="button" class="t8-row-menu-item t8-row-copy-ref" role="menuitem" data-copy="<?= e($ref) ?>">
                <i class="fa-solid fa-copy"></i> Copy Case Ref
            </button>
            <div class="t8-row-menu-divider"></div>
            <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('legal', ['action' => 'documents', 'id' => $id])) ?>">
                <i class="fa-solid fa-paperclip"></i> Documents
            </a>
            <?php if ($c['status'] === 'closed'): ?>
                <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('legal', ['action' => 'retention', 'id' => $id])) ?>">
                    <i class="fa-solid fa-box-archive"></i> <?= $retentionRecord !== null ? 'Manage Retention' : 'View Retention' ?>
                </a>
            <?php endif; ?>
            <?php if ($isAdmin && !$archivedFilter): ?>
                <div class="t8-row-menu-divider"></div>
                <a class="t8-row-menu-item" role="menuitem" href="<?= e(page_url('legal', ['action' => 'edit', 'id' => $id])) ?>">
                    <i class="fa-solid fa-pen"></i> Edit
                </a>
                <form method="post" action="<?= e(page_url('legal', ['action' => 'archive'])) ?>" onsubmit="return confirm('Archive this case?');">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <button class="t8-row-menu-item t8-danger" type="submit" role="menuitem">
                        <i class="fa-solid fa-box-archive"></i> Archive
                    </button>
                </form>
            <?php elseif ($isAdmin): ?>
                <div class="t8-row-menu-divider"></div>
                <form method="post" action="<?= e(page_url('legal', ['action' => 'restore'])) ?>">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <button class="t8-row-menu-item t8-success" type="submit" role="menuitem">
                        <i class="fa-solid fa-rotate-left"></i> Restore
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
?>
<h1>Legal Management</h1>
<p class="t8-help-text"><?= $isAdmin ? 'Track legal cases and their supporting documents.' : 'View legal cases assigned to you.' ?></p>

<?php foreach ($errors as $error): ?>
    <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if ($showForm): ?>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= $action === 'edit' ? 'Edit Legal Case' : 'New Legal Case' ?></h2>
        </div>
        <form method="post"
              action="<?= e(page_url('legal', array_filter(['action' => $action, 'id' => $_GET['id'] ?? null]))) ?>"
              class="t8-legal-form-grid"
              novalidate>
            <?= t8_csrf_field() ?>

            <div class="t8-field t8-form-span-2">
                <label class="t8-label" for="title">Case Title</label>
                <input class="t8-input" type="text" id="title" name="title"
                       value="<?= e($formValues['title']) ?>" required>
            </div>

            <?php if ($legalHasCaseMetadata): ?><div class="t8-field">
                <label class="t8-label" for="subject">Subject</label>
                <input class="t8-input" type="text" id="subject" name="subject" value="<?= e($formValues['subject']) ?>">
            </div>

            <div class="t8-field">
                <label class="t8-label" for="department_id">Department</label>
                <select class="t8-select" id="department_id" name="department_id"><option value="">Not assigned</option><?php foreach ($departments as $department): ?><option value="<?= e((string) $department['id']) ?>" <?= (string) $department['id'] === $formValues['department_id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option><?php endforeach; ?></select>
            </div><?php endif; ?>

            <div class="t8-field">
                <label class="t8-label" for="status">Status</label>
                <select class="t8-select" id="status" name="status" required>
                    <?php foreach (T8_LEGAL_STATUSES as $s): ?>
                        <option value="<?= e($s) ?>" <?= $s === $formValues['status'] ? 'selected' : '' ?>>
                            <?= e(ucwords(str_replace('_', ' ', $s))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($existing !== null && $existing['status'] === 'closed'): ?>
                    <span class="t8-help-text">This case is already closed<?= !empty($existing['closed_at']) ? ' (on ' . e(format_date((string) $existing['closed_at'], 'M d, Y')) . ')' : '' ?> and under retention. Manage disposal from its Retention screen, not by changing status here.</span>
                <?php else: ?>
                    <span class="t8-help-text">Setting this to "Closed" registers the case under retention automatically.</span>
                <?php endif; ?>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="filed_date">Filed Date</label>
                <input class="t8-input" type="date" id="filed_date" name="filed_date"
                       value="<?= e($formValues['filed_date']) ?>" required>
            </div>

            <?php if ($legalHasCaseMetadata): ?><div class="t8-field">
                <label class="t8-label" for="deadline">Deadline</label>
                <input class="t8-input" type="date" id="deadline" name="deadline" value="<?= e($formValues['deadline']) ?>" data-t8-date-rule="future">
            </div><?php endif; ?>

            <div class="t8-field">
                <label class="t8-label" for="assigned_to">Assigned To</label>
                <select class="t8-select" id="assigned_to" name="assigned_to" required>
                    <option value="">Select a person…</option>
                    <?php foreach ($assignees as $a): ?>
                        <option value="<?= e((string) $a['id']) ?>" <?= (string) $a['id'] === $formValues['assigned_to'] ? 'selected' : '' ?>>
                            <?= e($a['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="t8-form-actions t8-legal-form-actions">
                <button class="t8-btn t8-btn-accent" type="submit">
                    <i class="fa-solid fa-check"></i> <?= $action === 'edit' ? 'Save Changes' : 'Create Case' ?>
                </button>
                <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>">Cancel</a>
            </div>
        </form>
    </div>

<?php elseif ($showDocuments): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>">
            <i class="fa-solid fa-arrow-left"></i> Back to Cases
        </a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= e($case['title']) ?> — Attached Documents</h2>
        </div>

        <?php if ($isAdmin && $availableDocs === []): ?>
            <div class="t8-empty">No documents exist yet. Upload one in Document Management first.</div>
        <?php elseif ($isAdmin): ?>
            <form method="post" action="<?= e(page_url('legal', ['action' => 'documents', 'id' => $caseId])) ?>"
                  style="padding: 0 var(--t8-space-4) var(--t8-space-4);" novalidate>
                <?= t8_csrf_field() ?>
                <input type="hidden" name="form" value="attach">
                <div class="t8-field">
                    <label class="t8-label" for="document_id">Attach Document</label>
                    <select class="t8-select" id="document_id" name="document_id" required>
                        <option value="">Select a document…</option>
                        <?php foreach ($availableDocs as $d): ?>
                            <option value="<?= e((string) $d['id']) ?>"><?= e($d['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="t8-field">
                    <label class="t8-label" for="description">Note</label>
                    <input class="t8-input" type="text" id="description" name="description" placeholder="Optional">
                </div>
                <button class="t8-btn t8-btn-accent" type="submit">
                    <i class="fa-solid fa-paperclip"></i> Attach
                </button>
            </form>
        <?php endif; ?>

        <?php if ($attachedDocs === []): ?>
            <div class="t8-empty">No documents attached to this case yet.</div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th>Note</th>
                            <th>Attached On</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attachedDocs as $ad): ?>
                            <tr>
                                <td><?= e($ad['document_title']) ?></td>
                                <td><?= e((string) ($ad['description'] ?? '—')) ?></td>
                                <td><?= e(format_date($ad['created_at'], 'M d, Y g:i A')) ?></td>
                                <td style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <?php if ($isAdmin): ?><a class="t8-btn t8-btn-outline t8-btn-sm"
                                       href="<?= e(page_url('documents', ['action' => 'versions', 'id' => $ad['document_id']])) ?>">
                                        <i class="fa-solid fa-eye"></i> View
                                    </a><?php endif; ?>
                                    <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('documents', ['action' => 'download', 'version_id' => $ad['version_id']])) ?>"><i class="fa-solid fa-download"></i> Download</a>
                                    <?php if ($isAdmin): ?><form method="post" action="<?= e(page_url('legal', ['action' => 'detach_document'])) ?>"
                                          onsubmit="return confirm('Remove this document from the case?');">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="link_id" value="<?= e((string) $ad['id']) ?>">
                                        <input type="hidden" name="case_id" value="<?= e((string) $caseId) ?>">
                                        <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit">
                                            <i class="fa-solid fa-xmark"></i> Remove
                                        </button>
                                    </form><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($showRetention): ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>">
            <i class="fa-solid fa-arrow-left"></i> Back to Cases
        </a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= e($case['title']) ?> — Retention</h2>
        </div>

        <?php if ($retentionRecord === null): ?>
            <div class="t8-empty">
                This case is closed but has not been registered under retention yet.
                <?php if (!$legalHasClosedAt): ?>
                    <br><br>The <code>closed_at</code> column migration has not run yet - see
                    <code>database/migrations/2026_09_11_legal_case_closed_at.sql</code>.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="t8-hr-readonly-block">
                <div class="t8-hr-readonly-item"><span>Status</span><strong><span class="t8-badge <?= e(t8_retention_status_badge((string) $retentionRecord['status'])) ?>"><?= e(ucwords(str_replace('_', ' ', (string) $retentionRecord['status']))) ?></span></strong></div>
                <div class="t8-hr-readonly-item"><span>Retention Basis</span><strong><?= e((string) $retentionRecord['retention_basis']) ?></strong></div>
                <div class="t8-hr-readonly-item"><span>Retention Period</span><strong><?= e((string) $retentionRecord['retention_years']) ?> years</strong></div>
                <div class="t8-hr-readonly-item"><span>Clock Start</span><strong><?= e(format_date((string) $retentionRecord['retention_start_date'], 'M d, Y')) ?></strong></div>
                <div class="t8-hr-readonly-item"><span>Disposition Date</span><strong><?= e(format_date((string) $retentionRecord['disposition_date'], 'M d, Y')) ?></strong></div>
                <div class="t8-hr-readonly-item"><span>Custodian</span><strong><?= e((string) $retentionRecord['custodian_name']) ?></strong></div>
            </div>

            <?php if ($isAdmin && in_array($retentionRecord['status'], ['active', 'due_review'], true)): ?>
                <div class="t8-alert t8-alert-info">
                    Legal Case retention <strong>defaults to archive-only</strong>. Disposal requires two
                    <strong>different</strong> administrators - one to request it, another to authorize it -
                    given the case's evidentiary and legal significance.
                </div>
                <form method="post" action="<?= e(page_url('legal', ['action' => 'retention_archive'])) ?>" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="record_id" value="<?= e((string) $retentionRecord['id']) ?>">
                    <input type="hidden" name="case_id" value="<?= e((string) $caseId) ?>">
                    <div class="t8-field" style="margin-bottom:0; flex:1; min-width:220px;">
                        <label class="t8-label" for="archive_reason">Archive Reason</label>
                        <input class="t8-input" type="text" id="archive_reason" name="reason" required>
                    </div>
                    <button class="t8-btn t8-btn-outline" type="submit"><i class="fa-solid fa-box-archive"></i> Archive</button>
                </form>
            <?php elseif ($isAdmin && $retentionRecord['status'] === 'archived'): ?>
                <div class="t8-alert t8-alert-info">
                    Requesting disposal here does not dispose the record - a <strong>different</strong>
                    administrator must authorize it below before it is actually disposed.
                </div>
                <form method="post" action="<?= e(page_url('legal', ['action' => 'retention_dispose_request'])) ?>" style="display:flex; gap:8px; align-items:end; flex-wrap:wrap;">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="record_id" value="<?= e((string) $retentionRecord['id']) ?>">
                    <input type="hidden" name="case_id" value="<?= e((string) $caseId) ?>">
                    <div class="t8-field" style="margin-bottom:0; flex:1; min-width:220px;">
                        <label class="t8-label" for="dispose_reason">Disposal Reason</label>
                        <input class="t8-input" type="text" id="dispose_reason" name="reason" required>
                    </div>
                    <button class="t8-btn t8-btn-danger" type="submit"><i class="fa-solid fa-trash"></i> Request Disposal</button>
                </form>
            <?php elseif ($isAdmin && $retentionRecord['status'] === 'pending_disposal'): ?>
                <?php $isSameAdmin = (int) $retentionRecord['disposal_requested_by'] === $currentUserId; ?>
                <div class="t8-hr-readonly-block">
                    <div class="t8-hr-readonly-item"><span>Requested By</span><strong><?= e((string) $retentionRecord['disposal_requested_by_name']) ?></strong></div>
                    <div class="t8-hr-readonly-item"><span>Requested At</span><strong><?= e(format_date((string) $retentionRecord['disposal_requested_at'], 'M d, Y g:i A')) ?></strong></div>
                    <div class="t8-hr-readonly-item"><span>Reason</span><strong><?= e((string) $retentionRecord['disposal_reason']) ?></strong></div>
                </div>
                <?php if ($isSameAdmin): ?>
                    <div class="t8-alert t8-alert-warning">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        You requested this disposal. A <strong>different</strong> administrator must authorize it — dual control cannot be satisfied by the same account.
                    </div>
                    <button class="t8-btn t8-btn-danger" type="button" disabled title="A different administrator must authorize this">
                        <i class="fa-solid fa-lock"></i> Authorize Disposal (unavailable to you)
                    </button>
                <?php else: ?>
                    <form method="post" action="<?= e(page_url('legal', ['action' => 'retention_dispose_authorize'])) ?>" onsubmit="return confirm('Authorize disposal of this legal case record? This cannot be undone.');">
                        <?= t8_csrf_field() ?>
                        <input type="hidden" name="record_id" value="<?= e((string) $retentionRecord['id']) ?>">
                        <input type="hidden" name="case_id" value="<?= e((string) $caseId) ?>">
                        <button class="t8-btn t8-btn-danger" type="submit"><i class="fa-solid fa-check-double"></i> Authorize Disposal</button>
                    </form>
                <?php endif; ?>
            <?php elseif ($retentionRecord['status'] === 'disposed'): ?>
                <div class="t8-hr-readonly-block">
                    <div class="t8-hr-readonly-item"><span>Disposed At</span><strong><?= e(format_date((string) $retentionRecord['disposed_at'], 'M d, Y g:i A')) ?></strong></div>
                    <div class="t8-hr-readonly-item"><span>Authorized By</span><strong><?= e((string) $retentionRecord['disposal_authorized_by_name']) ?></strong></div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <?php if ($isAdmin): ?><a class="t8-btn t8-btn-accent" href="<?= e(page_url('legal', ['action' => 'create'])) ?>">
            <i class="fa-solid fa-plus"></i> New Legal Case
        </a><?php endif; ?>
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal', ['archived' => $archivedFilter ? '0' : '1'])) ?>">
            <i class="fa-solid fa-box-archive"></i> <?= $archivedFilter ? 'View Active' : 'View Archived' ?>
        </a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= $archivedFilter ? 'Archived Cases' : 'Legal Cases' ?></h2>
        </div>
        <?php if ($cases === []): ?>
            <div class="t8-empty"><?= $archivedFilter ? 'No archived cases.' : 'No legal cases yet.' ?></div>
        <?php else: ?>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <?php if ($legalHasCaseMetadata): ?><th>Subject</th>
                            <th>Department</th><?php endif; ?>
                            <th>Status</th>
                            <th>Filed Date</th>
                            <?php if ($legalHasCaseMetadata): ?><th>Deadline</th><?php endif; ?>
                            <th>Assigned To</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cases as $c): ?>
                            <?php
                            $isMonitoring = t8_legal_is_monitoring($c);
                            $retentionRecordForRow = function_exists('t8_retention_fetch_for_entity')
                                ? t8_retention_fetch_for_entity($pdo, 'legal_case', (int) $c['id'])
                                : null;
                            ?>
                            <tr>
                                <td><?= e($c['title']) ?></td>
                                <?php if ($legalHasCaseMetadata): ?><td><?= e((string) ($c['subject'] ?? '—')) ?></td>
                                <td><?= e((string) ($c['department_name'] ?? '—')) ?></td><?php endif; ?>
                                <td>
                                    <span class="t8-badge <?= t8_legal_status_badge($c['status']) ?>">
                                        <?= e(ucwords(str_replace('_', ' ', $c['status']))) ?>
                                    </span>
                                    <?php if ($isMonitoring): ?><span class="t8-badge-monitoring" title="Within 90 days of the case deadline">Monitoring</span><?php endif; ?>
                                </td>
                                <td><?= e(format_date($c['filed_date'], 'M d, Y')) ?></td>
                                <?php if ($legalHasCaseMetadata): ?><td><?= $c['deadline'] ? e(format_date($c['deadline'], 'M d, Y')) : '—' ?></td><?php endif; ?>
                                <td><?= e($c['assigned_to_name']) ?></td>
                                <td style="text-align:right;">
                                    <?php t8_legal_render_menu($c, $isAdmin, $archivedFilter, $legalHasCaseMetadata, $isMonitoring, $retentionRecordForRow); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!--
        Shared View Details modal for the Legal Cases list table's
        meatball menu (see t8_legal_render_menu() above).
    -->
    <dialog id="t8LegalDetailModal" class="t8-detail-modal">
        <div class="t8-detail-header">
            <div>
                <h2 data-detail-field="title">Legal Case</h2>
                <span class="t8-detail-ref" data-detail-field="ref"></span>
            </div>
            <button type="button" class="t8-detail-close" data-close-detail-modal aria-label="Close">&times;</button>
        </div>
        <div class="t8-detail-body">
            <div class="t8-detail-grid">
                <div class="t8-detail-item"><span>Status</span><strong data-detail-field="status">—</strong></div>
                <div class="t8-detail-item"><span>Assigned To</span><strong data-detail-field="assignedTo">—</strong></div>
                <div class="t8-detail-item" data-detail-wrap="department" hidden><span>Department</span><strong data-detail-field="department">—</strong></div>
                <div class="t8-detail-item"><span>Filed Date</span><strong data-detail-field="filed">—</strong></div>
                <div class="t8-detail-item" data-detail-wrap="deadline" hidden><span>Deadline</span><strong data-detail-field="deadline">—</strong></div>
            </div>
            <div id="t8LegalDetailSubjectWrap" data-detail-wrap="subject" hidden>
                <div class="t8-detail-section"><i class="fa-solid fa-file-lines"></i> Subject</div>
                <div class="t8-detail-notes" data-detail-field="subject"></div>
            </div>
        </div>
        <div class="t8-detail-footer">
            <button type="button" class="t8-btn t8-btn-outline" data-close-detail-modal>Close</button>
        </div>
    </dialog>

<?php endif; ?>
