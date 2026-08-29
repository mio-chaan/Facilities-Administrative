<?php
/**
 * modules/documents/hr/memorandum.php
 * Handles: memorandum_new (GET form / POST create, admin only),
 *          memorandum_edit (GET form / POST update — snapshots a
 *          version first if the document is already 'approved'),
 *          memorandum_view (GET), memorandum_status (POST).
 *
 * Also serves Warning Letters: same table/form, distinguished only by
 * the `kind` column (?kind=warning_letter). The task spec gives
 * Warning Letter no field set of its own, so it reuses this
 * structure (Title / Recipients / Content / Remarks / Prepared By)
 * rather than a near-duplicate table.
 */

declare(strict_types=1);

t8_require_role(['admin']);

if ($action === 'memorandum_new' || $action === 'memorandum_edit') {
    $editId = $action === 'memorandum_edit' ? (int) ($_GET['id'] ?? 0) : 0;
    $existing = $editId ? t8_hr_memorandum_fetch($pdo, $editId) : null;
    if ($action === 'memorandum_edit' && !$existing) {
        t8_flash_set('danger', 'Document not found.');
        redirect(page_url('documents'));
    }

    $kind = $existing['kind'] ?? ((string) ($_GET['kind'] ?? $_POST['kind'] ?? 'memorandum'));
    $kind = $kind === 'warning_letter' ? 'warning_letter' : 'memorandum';
    $label = $kind === 'warning_letter' ? 'Warning Letter' : 'Memorandum';

    $formValues = $existing !== null
        ? ['title' => $existing['title'], 'recipients' => $existing['recipients'], 'content' => $existing['content'], 'remarks' => (string) ($existing['remarks'] ?? '')]
        : ['title' => '', 'recipients' => '', 'content' => '', 'remarks' => ''];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $formValues = [
            'title'      => trim((string) ($_POST['title'] ?? '')),
            'recipients' => trim((string) ($_POST['recipients'] ?? '')),
            'content'    => trim((string) ($_POST['content'] ?? '')),
            'remarks'    => trim((string) ($_POST['remarks'] ?? '')),
        ];

        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            $errors[] = 'Your session expired. Please try again.';
        } else {
            if ($formValues['title'] === '') { $errors[] = 'Title is required.'; }
            if ($formValues['recipients'] === '') { $errors[] = 'Recipients are required.'; }
            if ($formValues['content'] === '') { $errors[] = 'Content is required.'; }

            if (!$errors && $action === 'memorandum_new') {
                $docNumber = t8_hr_generate_doc_number($pdo, $kind === 'warning_letter' ? 'WL' : 'MEMO', 'team8_memorandums');
                $stmt = $pdo->prepare(
                    'INSERT INTO team8_memorandums (document_number, kind, title, recipients, content, remarks, prepared_by, status)
                     VALUES (:document_number, :kind, :title, :recipients, :content, :remarks, :prepared_by, "draft")'
                );
                $stmt->execute([
                    'document_number' => $docNumber, 'kind' => $kind,
                    'title' => $formValues['title'], 'recipients' => $formValues['recipients'],
                    'content' => $formValues['content'], 'remarks' => $formValues['remarks'] !== '' ? $formValues['remarks'] : null,
                    'prepared_by' => $currentUserId,
                ]);
                $newId = (int) $pdo->lastInsertId();
                t8_audit_log($pdo, $currentUserId, 'memorandum', $newId, 'create');
                t8_flash_set('success', $label . ' ' . $docNumber . ' created as a draft.');
                redirect(page_url('documents', ['action' => 'memorandum_view', 'id' => $newId]));
            } elseif (!$errors) {
                // Editing an already-APPROVED document must not overwrite it —
                // snapshot the current row first.
                if ($existing['status'] === 'approved') {
                    $nextVersion = t8_hr_save_version($pdo, 'memorandum', $editId, $existing, $currentUserId);
                    $pdo->prepare('UPDATE team8_memorandums SET current_version = :v WHERE id = :id')
                        ->execute(['v' => $nextVersion + 1, 'id' => $editId]);
                }
                $pdo->prepare(
                    'UPDATE team8_memorandums SET title = :title, recipients = :recipients, content = :content, remarks = :remarks WHERE id = :id'
                )->execute([
                    'title' => $formValues['title'], 'recipients' => $formValues['recipients'],
                    'content' => $formValues['content'], 'remarks' => $formValues['remarks'] !== '' ? $formValues['remarks'] : null,
                    'id' => $editId,
                ]);
                t8_audit_log($pdo, $currentUserId, 'memorandum', $editId, 'update');
                t8_flash_set('success', $label . ' updated.');
                redirect(page_url('documents', ['action' => 'memorandum_view', 'id' => $editId]));
            }
        }
    }
    ?>
    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', $existing ? ['action' => 'memorandum_view', 'id' => $editId] : [])) ?>">
            <i class="fa-solid fa-arrow-left"></i> Back
        </a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
    <?php endforeach; ?>

    <div class="t8-card">
        <div class="t8-card-header"><h2 class="t8-card-title"><?= $existing ? 'Edit ' . e($label) : 'New ' . e($label) ?></h2></div>

        <div class="t8-hr-readonly-block">
            <div class="t8-hr-readonly-item"><span>Prepared By</span><strong><?= e(t8_current_user_name()) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Date</span><strong><?= e(date('M d, Y')) ?></strong></div>
        </div>

        <form method="post" action="<?= e(page_url('documents', $existing ? ['action' => 'memorandum_edit', 'id' => $editId] : ['action' => 'memorandum_new', 'kind' => $kind])) ?>" novalidate>
            <?= t8_csrf_field() ?>
            <input type="hidden" name="kind" value="<?= e($kind) ?>">

            <div class="t8-field">
                <label class="t8-label" for="title">Title</label>
                <input class="t8-input" type="text" id="title" name="title" value="<?= e($formValues['title']) ?>" required>
            </div>
            <div class="t8-field">
                <label class="t8-label" for="recipients">Recipients</label>
                <input class="t8-input" type="text" id="recipients" name="recipients" value="<?= e($formValues['recipients']) ?>" placeholder="e.g. All Employees, or a specific name/department" required>
            </div>
            <div class="t8-field">
                <label class="t8-label" for="content">Content</label>
                <textarea class="t8-textarea" id="content" name="content" rows="8" required><?= e($formValues['content']) ?></textarea>
            </div>
            <div class="t8-field">
                <label class="t8-label" for="remarks">Remarks <span class="t8-help-text">(optional)</span></label>
                <textarea class="t8-textarea" id="remarks" name="remarks" rows="2"><?= e($formValues['remarks']) ?></textarea>
            </div>

            <button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-check"></i> <?= $existing ? 'Save Changes' : 'Create ' . e($label) ?></button>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>">Cancel</a>
        </form>
    </div>
    <?php
    return;
}

if ($action === 'memorandum_view') {
    $id = (int) ($_GET['id'] ?? 0);
    $memo = $id ? t8_hr_memorandum_fetch($pdo, $id) : null;
    if (!$memo) {
        t8_flash_set('danger', 'Document not found.');
        redirect(page_url('documents'));
    }
    $label = $memo['kind'] === 'warning_letter' ? 'Warning Letter' : 'Memorandum';
    $versions = t8_hr_versions($pdo, 'memorandum', $id);
    ?>
    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <a class="t8-btn t8-btn-outline" target="_blank" href="<?= e(page_url('documents', ['action' => 'hr_print', 'type' => 'memorandum', 'id' => $id])) ?>">
            <i class="fa-solid fa-print"></i> Print
        </a>
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', ['action' => 'memorandum_edit', 'id' => $id])) ?>">
            <i class="fa-solid fa-pen"></i> Edit
        </a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= e($label) ?> — <?= e($memo['document_number']) ?></h2>
            <span class="t8-badge <?= e(t8_hr_status_badge((string) $memo['status'])) ?>"><?= e(ucfirst((string) $memo['status'])) ?></span>
        </div>

        <div class="t8-hr-readonly-block">
            <div class="t8-hr-readonly-item"><span>Title</span><strong><?= e($memo['title']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Recipients</span><strong><?= e($memo['recipients']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Prepared By</span><strong><?= e($memo['prepared_by_name']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Date</span><strong><?= e(format_date((string) $memo['created_at'], 'M d, Y')) ?></strong></div>
        </div>

        <div class="t8-field">
            <label class="t8-label">Content</label>
            <p style="white-space:pre-wrap;"><?= nl2br(e((string) $memo['content'])) ?></p>
        </div>
        <?php if (!empty($memo['remarks'])): ?>
            <div class="t8-field">
                <label class="t8-label">Remarks</label>
                <p><?= nl2br(e((string) $memo['remarks'])) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($memo['status'] === 'draft' || $memo['status'] === 'pending'): ?>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <?php if ($memo['status'] === 'draft'): ?>
                    <form method="post" action="<?= e(page_url('documents', ['action' => 'memorandum_status'])) ?>">
                        <?= t8_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                        <input type="hidden" name="status" value="pending">
                        <button class="t8-btn t8-btn-outline t8-btn-sm" type="submit"><i class="fa-solid fa-paper-plane"></i> Send for Approval</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= e(page_url('documents', ['action' => 'memorandum_status'])) ?>">
                        <?= t8_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                        <input type="hidden" name="status" value="approved">
                        <button class="t8-btn t8-btn-success t8-btn-sm" type="submit"><i class="fa-solid fa-check"></i> Approve</button>
                    </form>
                    <form method="post" action="<?= e(page_url('documents', ['action' => 'memorandum_status'])) ?>">
                        <?= t8_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                        <input type="hidden" name="status" value="rejected">
                        <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit"><i class="fa-solid fa-xmark"></i> Reject</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php elseif ($memo['status'] !== 'archived'): ?>
            <form method="post" action="<?= e(page_url('documents', ['action' => 'memorandum_status'])) ?>" onsubmit="return confirm('Archive this document?');">
                <?= t8_csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                <input type="hidden" name="status" value="archived">
                <button class="t8-btn t8-btn-outline t8-btn-sm" type="submit"><i class="fa-solid fa-box-archive"></i> Archive</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($versions !== []): ?>
        <div class="t8-card">
            <div class="t8-card-header"><h2 class="t8-card-title">Version History</h2></div>
            <div class="t8-table-wrap">
                <table class="t8-table">
                    <thead><tr><th>Version</th><th>Saved By</th><th>Saved At</th></tr></thead>
                    <tbody>
                        <?php foreach ($versions as $v): ?>
                            <tr><td>v<?= e((string) $v['version_no']) ?></td><td><?= e($v['created_by_name']) ?></td><td><?= e(format_date((string) $v['created_at'], 'M d, Y g:i A')) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    <?php
    return;
}

if ($action === 'memorandum_status') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        redirect(page_url('documents'));
    }
    if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
        t8_flash_set('danger', 'Your session expired. Please try again.');
        redirect(page_url('documents'));
    }

    $id = (int) ($_POST['id'] ?? 0);
    $newStatus = (string) ($_POST['status'] ?? '');
    if (!in_array($newStatus, T8_HR_STATUSES, true)) {
        t8_flash_set('danger', 'Invalid status.');
        redirect(page_url('documents', ['action' => 'memorandum_view', 'id' => $id]));
    }

    $memo = t8_hr_memorandum_fetch($pdo, $id);
    if ($memo) {
        $pdo->prepare('UPDATE team8_memorandums SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => $id]);
        t8_audit_log($pdo, $currentUserId, 'memorandum', $id, $newStatus);
        t8_flash_set('success', 'Document marked ' . $newStatus . '.');
    } else {
        t8_flash_set('danger', 'Document not found.');
    }
    redirect(page_url('documents', ['action' => 'memorandum_view', 'id' => $id]));
}
