<?php
/**
 * modules/documents/hr/explanation.php
 * Handles: explanation_new (GET form / POST submit — ONLY the NTE's
 *          own employee, verified against session, may submit),
 *          explanation_view (GET), explanation_review (POST,
 *          admin only, approve/reject with remarks).
 *
 * Workflow: Incident Report -> NTE -> Explanation -> Admin Review -> Archive
 */

declare(strict_types=1);

if ($action === 'explanation_new') {
    $nteId = (int) ($_GET['nte_id'] ?? ($_POST['nte_id'] ?? 0));
    $nte = $nteId ? t8_hr_nte_fetch($pdo, $nteId) : null;
    if (!$nte) {
        t8_flash_set('danger', 'Notice To Explain not found.');
        redirect(page_url('documents'));
    }
    // SECURITY: only the employee the NTE was issued to may submit an
    // explanation for it — this is checked against the session user
    // id, never against a value supplied in the form.
    if ((int) $nte['employee_id'] !== $currentUserId) {
        http_response_code(403);
        echo '<div class="t8-alert t8-alert-danger">403 — This notice was not issued to you.</div>';
        return;
    }
    if (t8_hr_explanation_for_nte($pdo, $nteId)) {
        t8_flash_set('danger', 'You have already submitted an explanation for this notice.');
        redirect(page_url('documents', ['action' => 'nte_view', 'id' => $nteId]));
    }

    $formValues = ['explanation_text' => ''];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $formValues['explanation_text'] = trim((string) ($_POST['explanation_text'] ?? ''));

        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            $errors[] = 'Your session expired. Please try again.';
        } else {
            if ($formValues['explanation_text'] === '') {
                $errors[] = 'Please write your explanation before submitting.';
            }

            $attachmentPath = null;
            if (!$errors && !empty($_FILES['attachment']['name'])) {
                try {
                    $attachmentPath = t8_hr_store_attachment($_FILES['attachment'], 'explanation');
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }

            if (!$errors) {
                $stmt = $pdo->prepare(
                    'INSERT INTO team8_explanations (nte_id, employee_id, explanation_text, attachment_path, status)
                     VALUES (:nte_id, :employee_id, :explanation_text, :attachment_path, "pending")'
                );
                $stmt->execute([
                    'nte_id'           => $nteId,
                    'employee_id'      => $currentUserId,
                    'explanation_text' => $formValues['explanation_text'],
                    'attachment_path'  => $attachmentPath,
                ]);
                $newId = (int) $pdo->lastInsertId();

                t8_audit_log($pdo, $currentUserId, 'explanation', $newId, 'submit');
                t8_hr_notify_admins($pdo, 'An explanation letter was submitted for ' . $nte['document_number'] . ' by ' . $nte['employee_name'] . '.');
                t8_flash_set('success', 'Your explanation was submitted.');
                redirect(page_url('documents', ['action' => 'explanation_view', 'id' => $newId]));
            }
        }
    }
    ?>
    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', ['action' => 'nte_view', 'id' => $nteId])) ?>">
            <i class="fa-solid fa-arrow-left"></i> Back to Notice
        </a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
    <?php endforeach; ?>

    <div class="t8-card">
        <div class="t8-card-header"><h2 class="t8-card-title">Submit Explanation — <?= e($nte['document_number']) ?></h2></div>
        <div class="t8-hr-readonly-block">
            <div class="t8-hr-readonly-item"><span>Deadline</span><strong><?= e(format_date((string) $nte['deadline'], 'M d, Y')) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Incident Type</span><strong><?= e((string) $nte['incident_type']) ?></strong></div>
        </div>

        <form method="post" action="<?= e(page_url('documents', ['action' => 'explanation_new', 'nte_id' => $nteId])) ?>" enctype="multipart/form-data" novalidate>
            <?= t8_csrf_field() ?>
            <input type="hidden" name="nte_id" value="<?= e((string) $nteId) ?>">

            <div class="t8-field">
                <label class="t8-label" for="explanation_text">Your Explanation</label>
                <textarea class="t8-textarea" id="explanation_text" name="explanation_text" rows="6" required><?= e($formValues['explanation_text']) ?></textarea>
            </div>
            <div class="t8-field">
                <label class="t8-label" for="attachment">Attachment <span class="t8-help-text">(optional)</span></label>
                <input class="t8-input" type="file" id="attachment" name="attachment">
            </div>

            <button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-check"></i> Submit Explanation</button>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', ['action' => 'nte_view', 'id' => $nteId])) ?>">Cancel</a>
        </form>
    </div>
    <?php
    return;
}

if ($action === 'explanation_view') {
    $id = (int) ($_GET['id'] ?? 0);
    $explanation = $id ? t8_hr_explanation_fetch($pdo, $id) : null;
    if (!$explanation) {
        t8_flash_set('danger', 'Explanation letter not found.');
        redirect(page_url('documents'));
    }
    if (!$isAdmin && (int) $explanation['employee_id'] !== $currentUserId) {
        http_response_code(403);
        echo '<div class="t8-alert t8-alert-danger">403 — You do not have permission to view this explanation.</div>';
        return;
    }
    ?>
    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Explanation Letter — <?= e($explanation['nte_number']) ?></h2>
            <span class="t8-badge <?= e(t8_hr_status_badge((string) $explanation['status'])) ?>"><?= e(ucfirst((string) $explanation['status'])) ?></span>
        </div>

        <div class="t8-hr-readonly-block">
            <div class="t8-hr-readonly-item"><span>Employee</span><strong><?= e($explanation['employee_name']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Submitted</span><strong><?= e(format_date((string) $explanation['submitted_at'], 'M d, Y g:i A')) ?></strong></div>
        </div>

        <div class="t8-field">
            <label class="t8-label">Explanation</label>
            <p style="white-space:pre-wrap;"><?= nl2br(e((string) $explanation['explanation_text'])) ?></p>
        </div>

        <?php if (!empty($explanation['attachment_path'])): ?>
            <div class="t8-field">
                <a href="<?= e(page_url('documents', ['action' => 'hr_attachment_download', 'type' => 'explanation', 'id' => $id])) ?>" target="_blank"><i class="fa-solid fa-paperclip"></i> View attachment</a>
            </div>
        <?php endif; ?>

        <?php if ($explanation['status'] !== 'pending'): ?>
            <div class="t8-field">
                <label class="t8-label">Admin Remarks</label>
                <p><?= e((string) ($explanation['admin_remarks'] ?? '—')) ?></p>
                <span class="t8-help-text">Reviewed by <?= e((string) ($explanation['reviewed_by_name'] ?? '—')) ?> on <?= e(format_date((string) $explanation['reviewed_at'], 'M d, Y g:i A')) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($isAdmin && $explanation['status'] === 'pending'): ?>
            <form method="post" action="<?= e(page_url('documents', ['action' => 'explanation_review'])) ?>" novalidate>
                <?= t8_csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                <div class="t8-field">
                    <label class="t8-label" for="admin_remarks">Remarks <span class="t8-help-text">(optional)</span></label>
                    <textarea class="t8-textarea" id="admin_remarks" name="admin_remarks" rows="3"></textarea>
                </div>
                <button class="t8-btn t8-btn-success t8-btn-sm" type="submit" name="status" value="approved"><i class="fa-solid fa-check"></i> Approve</button>
                <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit" name="status" value="rejected"><i class="fa-solid fa-xmark"></i> Reject</button>
            </form>
        <?php elseif ($isAdmin && $explanation['status'] !== 'archived'): ?>
            <form method="post" action="<?= e(page_url('documents', ['action' => 'explanation_review'])) ?>" onsubmit="return confirm('Archive this explanation?');">
                <?= t8_csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                <input type="hidden" name="status" value="archived">
                <button class="t8-btn t8-btn-outline t8-btn-sm" type="submit"><i class="fa-solid fa-box-archive"></i> Archive</button>
            </form>
        <?php endif; ?>
    </div>
    <?php
    return;
}

if ($action === 'explanation_review') {
    t8_require_role(['admin']);
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
    $remarks = trim((string) ($_POST['admin_remarks'] ?? ''));
    if (!in_array($newStatus, ['approved', 'rejected', 'archived'], true)) {
        t8_flash_set('danger', 'Invalid status.');
        redirect(page_url('documents', ['action' => 'explanation_view', 'id' => $id]));
    }

    $explanation = t8_hr_explanation_fetch($pdo, $id);
    if ($explanation) {
        $pdo->prepare(
            'UPDATE team8_explanations SET status = :status, admin_remarks = :remarks, reviewed_by = :reviewer, reviewed_at = NOW() WHERE id = :id'
        )->execute([
            'status'   => $newStatus,
            'remarks'  => $remarks !== '' ? $remarks : null,
            'reviewer' => $currentUserId,
            'id'       => $id,
        ]);
        t8_audit_log($pdo, $currentUserId, 'explanation', $id, $newStatus);
        t8_hr_notify($pdo, (int) $explanation['employee_id'], 'Your explanation for ' . $explanation['nte_number'] . ' was marked ' . $newStatus . '.');
        t8_flash_set('success', 'Explanation ' . $newStatus . '.');
    } else {
        t8_flash_set('danger', 'Explanation letter not found.');
    }
    redirect(page_url('documents', ['action' => 'explanation_view', 'id' => $id]));
}
