<?php
/**
 * modules/legal/index.php
 * Legal Management - Administrator only.
 *
 * Backing tables:
 *   team8_legal_cases     (id, assigned_to, contract_id, title, status,
 *     filed_date, created_at, updated_at, deleted_at)
 *   team8_legal_documents (id, case_id, document_id, description,
 *     created_at) - links an existing Document Management document to
 *     a case; attaching/removing here never touches the document
 *     itself, only the link row.
 *
 * contract_id is nullable and intentionally left unset by this form
 * for now - it gets wired up once Contract Management exists.
 *
 * Access: Administrator only. Facilities Staff never see this module
 * in the nav, but this guard blocks direct URL access too.
 */

declare(strict_types=1);

t8_require_role(['admin']);

$pageTitle = 'Legal Management';
$currentUserId = t8_current_user_id();
$action = $_GET['action'] ?? 'list';
$errors = [];

const T8_LEGAL_STATUSES = ['open', 'in_progress', 'closed', 'dismissed'];

/** Fetch one legal case with assignee name, or null. */
function t8_legal_case_fetch(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT lc.*, u.full_name AS assigned_to_name
         FROM team8_legal_cases lc
         JOIN users u ON u.id = lc.assigned_to
         WHERE lc.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

$assignees = $pdo->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC);

switch ($action) {
    case 'create':
    case 'edit':
        $caseId = $action === 'edit' ? (int) ($_GET['id'] ?? 0) : 0;
        $existing = $caseId ? t8_legal_case_fetch($pdo, $caseId) : null;
        if ($action === 'edit' && !$existing) {
            t8_flash_set('danger', 'Legal case not found.');
            redirect(page_url('legal'));
        }

        $formValues = $existing !== null
            ? [
                'title'       => $existing['title'],
                'status'      => $existing['status'],
                'filed_date'  => $existing['filed_date'],
                'assigned_to' => (string) $existing['assigned_to'],
            ]
            : ['title' => '', 'status' => 'open', 'filed_date' => date('Y-m-d'), 'assigned_to' => (string) $currentUserId];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $formValues = [
                'title'       => trim((string) ($_POST['title'] ?? '')),
                'status'      => (string) ($_POST['status'] ?? 'open'),
                'filed_date'  => trim((string) ($_POST['filed_date'] ?? '')),
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
                if (!$formValues['assigned_to']) {
                    $errors[] = 'Please assign this case to someone.';
                }

                if (!$errors) {
                    if ($action === 'create') {
                        $stmt = $pdo->prepare(
                            'INSERT INTO team8_legal_cases (assigned_to, title, status, filed_date)
                             VALUES (:assigned_to, :title, :status, :filed_date)'
                        );
                        $stmt->execute([
                            'assigned_to' => (int) $formValues['assigned_to'],
                            'title'       => $formValues['title'],
                            'status'      => $formValues['status'],
                            'filed_date'  => $formValues['filed_date'],
                        ]);
                        $newId = (int) $pdo->lastInsertId();
                        t8_audit_log($pdo, $currentUserId, 'legal_case', $newId, 'create');
                        t8_flash_set('success', 'Legal case created.');
                    } else {
                        $pdo->prepare(
                            'UPDATE team8_legal_cases SET assigned_to = :assigned_to, title = :title, status = :status, filed_date = :filed_date WHERE id = :id'
                        )->execute([
                            'assigned_to' => (int) $formValues['assigned_to'],
                            'title'       => $formValues['title'],
                            'status'      => $formValues['status'],
                            'filed_date'  => $formValues['filed_date'],
                            'id'          => $caseId,
                        ]);
                        t8_audit_log($pdo, $currentUserId, 'legal_case', $caseId, 'update');
                        t8_flash_set('success', 'Legal case updated.');
                    }
                    redirect(page_url('legal'));
                }
            }
        }
        break;

    case 'archive':
    case 'restore':
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

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'attach') {
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
            'SELECT ld.*, d.title AS document_title
             FROM team8_legal_documents ld
             JOIN team8_documents d ON d.id = ld.document_id
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
}

$showForm = in_array($action, ['create', 'edit'], true);
$showDocuments = $action === 'documents';
$showList = !$showForm && !$showDocuments;

if ($showList) {
    $statusFilter = $_GET['status'] ?? 'all';
    $archivedFilter = ($_GET['archived'] ?? '0') === '1';
    $where = $archivedFilter ? 'lc.deleted_at IS NOT NULL' : 'lc.deleted_at IS NULL';
    $params = [];
    if (in_array($statusFilter, T8_LEGAL_STATUSES, true)) {
        $where .= ' AND lc.status = :status';
        $params['status'] = $statusFilter;
    }
    $stmt = $pdo->prepare(
        "SELECT lc.*, u.full_name AS assigned_to_name
         FROM team8_legal_cases lc
         JOIN users u ON u.id = lc.assigned_to
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
        'in_progress' => 't8-badge-pending',
        'closed'      => 't8-badge-approved',
        'dismissed'   => 't8-badge-rejected',
    ];
    return $map[$status] ?? 't8-badge-pending';
}
?>
<h1>Legal Management</h1>
<p class="t8-help-text">Track legal cases and their supporting documents. Administrator only.</p>

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
              novalidate>
            <?= t8_csrf_field() ?>

            <div class="t8-field">
                <label class="t8-label" for="title">Case Title</label>
                <input class="t8-input" type="text" id="title" name="title"
                       value="<?= e($formValues['title']) ?>" required>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="status">Status</label>
                <select class="t8-select" id="status" name="status" required>
                    <?php foreach (T8_LEGAL_STATUSES as $s): ?>
                        <option value="<?= e($s) ?>" <?= $s === $formValues['status'] ? 'selected' : '' ?>>
                            <?= e(ucwords(str_replace('_', ' ', $s))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="filed_date">Filed Date</label>
                <input class="t8-input" type="date" id="filed_date" name="filed_date"
                       value="<?= e($formValues['filed_date']) ?>" required>
            </div>

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

            <button class="t8-btn t8-btn-accent" type="submit">
                <i class="fa-solid fa-check"></i> <?= $action === 'edit' ? 'Save Changes' : 'Create Case' ?>
            </button>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('legal')) ?>">Cancel</a>
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

        <?php if ($availableDocs === []): ?>
            <div class="t8-empty">No documents exist yet. Upload one in Document Management first.</div>
        <?php else: ?>
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
                                    <a class="t8-btn t8-btn-outline t8-btn-sm"
                                       href="<?= e(page_url('documents', ['action' => 'versions', 'id' => $ad['document_id']])) ?>">
                                        <i class="fa-solid fa-eye"></i> View
                                    </a>
                                    <form method="post" action="<?= e(page_url('legal', ['action' => 'detach_document'])) ?>"
                                          onsubmit="return confirm('Remove this document from the case?');">
                                        <?= t8_csrf_field() ?>
                                        <input type="hidden" name="link_id" value="<?= e((string) $ad['id']) ?>">
                                        <input type="hidden" name="case_id" value="<?= e((string) $caseId) ?>">
                                        <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit">
                                            <i class="fa-solid fa-xmark"></i> Remove
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-accent" href="<?= e(page_url('legal', ['action' => 'create'])) ?>">
            <i class="fa-solid fa-plus"></i> New Legal Case
        </a>
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
                            <th>Status</th>
                            <th>Filed Date</th>
                            <th>Assigned To</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cases as $c): ?>
                            <tr>
                                <td><?= e($c['title']) ?></td>
                                <td>
                                    <span class="t8-badge <?= t8_legal_status_badge($c['status']) ?>">
                                        <?= e(ucwords(str_replace('_', ' ', $c['status']))) ?>
                                    </span>
                                </td>
                                <td><?= e(format_date($c['filed_date'], 'M d, Y')) ?></td>
                                <td><?= e($c['assigned_to_name']) ?></td>
                                <td style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('legal', ['action' => 'documents', 'id' => $c['id']])) ?>">
                                        <i class="fa-solid fa-paperclip"></i> Documents
                                    </a>
                                    <?php if (!$archivedFilter): ?>
                                        <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('legal', ['action' => 'edit', 'id' => $c['id']])) ?>">
                                            <i class="fa-solid fa-pen"></i> Edit
                                        </a>
                                        <form method="post" action="<?= e(page_url('legal', ['action' => 'archive'])) ?>"
                                              onsubmit="return confirm('Archive this case?');">
                                            <?= t8_csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $c['id']) ?>">
                                            <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit">
                                                <i class="fa-solid fa-box-archive"></i> Archive
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="<?= e(page_url('legal', ['action' => 'restore'])) ?>">
                                            <?= t8_csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= e((string) $c['id']) ?>">
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

<?php endif; ?>