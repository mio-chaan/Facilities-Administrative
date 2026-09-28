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

if ($action !== 'memorandum_view') {
    t8_require_role(['admin']);
}

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
    $departments = t8_hr_departments($pdo);
    $recipientValues = $existing !== null
        ? array_map(
            static fn (array $row): string => $row['recipient_type'] === 'all_departments' ? 'all_departments' : (string) $row['department_id'],
            t8_hr_memorandum_recipients($pdo, $editId)
        )
        : [];

    $formValues = $existing !== null
        ? ['title' => $existing['title'], 'content' => $existing['content'], 'remarks' => (string) ($existing['remarks'] ?? '')]
        : ['title' => '', 'content' => '', 'remarks' => ''];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $recipientValues = isset($_POST['recipients']) && is_array($_POST['recipients']) ? array_values($_POST['recipients']) : [];
        $formValues = [
            'title'      => trim((string) ($_POST['title'] ?? '')),
            'content'    => trim((string) ($_POST['content'] ?? '')),
            'remarks'    => trim((string) ($_POST['remarks'] ?? '')),
        ];

        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            $errors[] = 'Your session expired. Please try again.';
        } else {
            if ($formValues['title'] === '') { $errors[] = 'Title is required.'; }
            if ($formValues['content'] === '') { $errors[] = 'Content is required.'; }
            $recipientSelection = t8_hr_recipient_selection($pdo, $recipientValues);
            if ($recipientSelection['error'] !== null) { $errors[] = $recipientSelection['error']; }

            if (!$errors && $action === 'memorandum_new') {
                $docNumber = t8_hr_generate_doc_number($pdo, $kind === 'warning_letter' ? 'WL' : 'MEMO', 'team8_memorandums');
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO team8_memorandums (document_number, kind, title, recipients, content, remarks, prepared_by, status)
                         VALUES (:document_number, :kind, :title, :recipients, :content, :remarks, :prepared_by, "draft")'
                    );
                    $stmt->execute([
                        'document_number' => $docNumber, 'kind' => $kind,
                        'title' => $formValues['title'], 'recipients' => implode(', ', $recipientSelection['labels']),
                        'content' => $formValues['content'], 'remarks' => $formValues['remarks'] !== '' ? $formValues['remarks'] : null,
                        'prepared_by' => $currentUserId,
                    ]);
                    $newId = (int) $pdo->lastInsertId();
                    $recipientStmt = $pdo->prepare(
                        'INSERT INTO team8_memorandum_recipients (memorandum_id, recipient_type, department_id)
                         VALUES (:memorandum_id, :recipient_type, :department_id)'
                    );
                    foreach ($recipientSelection['rows'] as $recipient) {
                        $recipientStmt->execute([
                            'memorandum_id' => $newId,
                            'recipient_type' => $recipient['recipient_type'],
                            'department_id' => $recipient['department_id'],
                        ]);
                    }
                    $pdo->commit();
                } catch (Throwable $exception) {
                    $pdo->rollBack();
                    throw $exception;
                }
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
                $pdo->beginTransaction();
                try {
                    $pdo->prepare(
                        'UPDATE team8_memorandums SET title = :title, recipients = :recipients, content = :content, remarks = :remarks WHERE id = :id'
                    )->execute([
                        'title' => $formValues['title'], 'recipients' => implode(', ', $recipientSelection['labels']),
                        'content' => $formValues['content'], 'remarks' => $formValues['remarks'] !== '' ? $formValues['remarks'] : null,
                        'id' => $editId,
                    ]);
                    $pdo->prepare('DELETE FROM team8_memorandum_recipients WHERE memorandum_id = :id')->execute(['id' => $editId]);
                    $recipientStmt = $pdo->prepare(
                        'INSERT INTO team8_memorandum_recipients (memorandum_id, recipient_type, department_id)
                         VALUES (:memorandum_id, :recipient_type, :department_id)'
                    );
                    foreach ($recipientSelection['rows'] as $recipient) {
                        $recipientStmt->execute([
                            'memorandum_id' => $editId,
                            'recipient_type' => $recipient['recipient_type'],
                            'department_id' => $recipient['department_id'],
                        ]);
                    }
                    $pdo->commit();
                } catch (Throwable $exception) {
                    $pdo->rollBack();
                    throw $exception;
                }
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
                <span class="t8-label" id="recipients-label">Recipients</span>
                <div class="t8-recipient-picker" data-recipient-picker>
                    <button class="t8-input t8-recipient-trigger" type="button" aria-haspopup="true" aria-expanded="false" aria-labelledby="recipients-label" data-recipient-trigger>
                        <span data-recipient-summary><?= $recipientValues === [] ? 'Select departments' : e(implode(', ', $recipientSelection['labels'] ?? $existing['recipient_labels'] ?? [])) ?></span>
                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="t8-recipient-panel" data-recipient-panel hidden>
                        <input class="t8-input t8-recipient-search" type="search" placeholder="Search departments..." aria-label="Search departments" data-recipient-search>
                        <label class="t8-recipient-option" data-recipient-option>
                            <input type="checkbox" name="recipients[]" value="all_departments" data-recipient-checkbox <?= in_array('all_departments', $recipientValues, true) ? 'checked' : '' ?>>
                            <span>All Departments</span>
                        </label>
                        <?php foreach ($departments as $department): ?>
                            <label class="t8-recipient-option" data-recipient-option data-recipient-label="<?= e(strtolower($department['name'])) ?>">
                                <input type="checkbox" name="recipients[]" value="<?= e((string) $department['id']) ?>" data-recipient-checkbox <?= in_array((string) $department['id'], $recipientValues, true) ? 'checked' : '' ?>>
                                <span><?= e($department['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <span class="t8-help-text">Choose All Departments or one or more departments.</span>
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
            <div class="t8-hr-readonly-item"><span>Recipients</span><strong><?= e(implode(', ', $memo['recipient_labels'] ?? [])) ?></strong></div>
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
                    <form class="t8-hr-decision-form" method="post" action="<?= e(page_url('documents', ['action' => 'memorandum_status'])) ?>">
                        <?= t8_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                        <textarea class="t8-textarea" name="rejection_reason" rows="3" placeholder="Enter reason for rejection..."></textarea>
                        <div class="t8-hr-decision-actions">
                            <button class="t8-btn t8-btn-success t8-btn-sm" type="submit" name="status" value="approved"><i class="fa-solid fa-check"></i> Approve</button>
                            <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit" name="status" value="rejected"><i class="fa-solid fa-xmark"></i> Reject</button>
                        </div>
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
        <?php if ($memo['status'] === 'rejected'): ?>
            <div class="t8-field" style="margin-top: var(--t8-space-4);">
                <label class="t8-label">Rejection Reason</label>
                <p><?= nl2br(e((string) ($memo['rejection_reason'] ?? '—'))) ?></p>
            </div>
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
    $rejectionReason = trim((string) ($_POST['rejection_reason'] ?? ''));
    if (!in_array($newStatus, T8_HR_STATUSES, true)) {
        t8_flash_set('danger', 'Invalid status.');
        redirect(page_url('documents', ['action' => 'memorandum_view', 'id' => $id]));
    }
    if ($newStatus === 'rejected' && $rejectionReason === '') {
        t8_flash_set('danger', 'A rejection reason is required.');
        redirect(page_url('documents', ['action' => 'memorandum_view', 'id' => $id]));
    }

    $memo = t8_hr_memorandum_fetch($pdo, $id);
    if ($memo) {
        $pdo->prepare('UPDATE team8_memorandums SET status = :status, rejection_reason = :reason WHERE id = :id')
            ->execute(['status' => $newStatus, 'reason' => $newStatus === 'rejected' ? $rejectionReason : null, 'id' => $id]);
        t8_audit_log($pdo, $currentUserId, 'memorandum', $id, $newStatus);
        if ($newStatus === 'rejected') {
            $recipientRows = t8_hr_memorandum_recipients($pdo, $id);
            $recipientIds = [];
            $allDepartments = false;
            $departmentIds = [];
            foreach ($recipientRows as $recipient) {
                if ($recipient['recipient_type'] === 'all_departments') {
                    $allDepartments = true;
                    break;
                }
                if ($recipient['department_id'] !== null) {
                    $departmentIds[] = (int) $recipient['department_id'];
                }
            }
            if ($allDepartments) {
                $recipientIds = $pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN);
            } elseif ($departmentIds !== []) {
                $placeholders = implode(',', array_fill(0, count($departmentIds), '?'));
                $recipientStmt = $pdo->prepare("SELECT id FROM users WHERE department_id IN ($placeholders)");
                $recipientStmt->execute(array_values(array_unique($departmentIds)));
                $recipientIds = $recipientStmt->fetchAll(PDO::FETCH_COLUMN);
            }
            $notificationMessage = 'The ' . ($memo['kind'] === 'warning_letter' ? 'warning letter' : 'memorandum') . ' ' . $memo['document_number'] . ' was rejected. Reason: ' . $rejectionReason;
            foreach (array_unique(array_map('intval', $recipientIds)) as $recipientId) {
                t8_hr_notify(
                    $pdo,
                    $recipientId,
                    $notificationMessage,
                    page_url('documents', ['action' => 'memorandum_view', 'id' => $id])
                );
            }
        }
        t8_flash_set('success', 'Document marked ' . $newStatus . '.');
    } else {
        t8_flash_set('danger', 'Document not found.');
    }
    redirect(page_url('documents', ['action' => 'memorandum_view', 'id' => $id]));
}
