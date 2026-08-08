<?php
/**
 * modules/documents/hr/certificate.php
 * Handles: certificate_new (type picker when no ?type=, then GET
 *          form / POST create, admin only), certificate_view (GET),
 *          certificate_status (POST approve/reject/archive).
 *
 * Employee information (name/department) is never duplicated onto
 * the certificate row — only employee_id is stored, resolved via
 * JOIN at view/print time (see t8_hr_certificate_fetch()).
 */

declare(strict_types=1);

t8_require_role(['admin']);

if ($action === 'certificate_new') {
    $type = (string) ($_GET['type'] ?? $_POST['certificate_type'] ?? '');

    if (!array_key_exists($type, T8_CERTIFICATE_TYPES)) {
        ?>
        <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', ['action' => 'generate'])) ?>"><i class="fa-solid fa-arrow-left"></i> Back to Templates</a>
        </div>
        <div class="t8-card">
            <div class="t8-card-header"><h2 class="t8-card-title">Choose Certificate Type</h2></div>
            <div class="t8-template-grid">
                <?php foreach (T8_CERTIFICATE_TYPES as $key => $certLabel): ?>
                    <a class="t8-template-box" style="text-decoration:none;" href="<?= e(page_url('documents', ['action' => 'certificate_new', 'type' => $key])) ?>">
                        <i class="fa-solid fa-certificate"></i>
                        <span><?= e($certLabel) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return;
    }

    $employees = $pdo->query('SELECT id, full_name FROM users ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC);
    $formValues = ['employee_id' => '', 'details' => ''];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $formValues = [
            'employee_id' => (string) ($_POST['employee_id'] ?? ''),
            'details'     => trim((string) ($_POST['details'] ?? '')),
        ];

        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            $errors[] = 'Your session expired. Please try again.';
        } else {
            $employeeId = (int) $formValues['employee_id'];
            $validEmployee = false;
            foreach ($employees as $emp) {
                if ((int) $emp['id'] === $employeeId) { $validEmployee = true; break; }
            }
            if (!$validEmployee) {
                $errors[] = 'Please select a valid employee.';
            }

            if (!$errors) {
                $docNumber = t8_hr_generate_doc_number($pdo, 'CERT-' . strtoupper(substr($type, 0, 3)), 'team8_certificates');
                $stmt = $pdo->prepare(
                    'INSERT INTO team8_certificates (document_number, certificate_type, employee_id, prepared_by, details, status)
                     VALUES (:document_number, :certificate_type, :employee_id, :prepared_by, :details, "draft")'
                );
                $stmt->execute([
                    'document_number'  => $docNumber,
                    'certificate_type' => $type,
                    'employee_id'      => $employeeId,
                    'prepared_by'      => $currentUserId,
                    'details'          => $formValues['details'] !== '' ? $formValues['details'] : null,
                ]);
                $newId = (int) $pdo->lastInsertId();
                t8_audit_log($pdo, $currentUserId, 'certificate', $newId, 'create');
                t8_hr_notify($pdo, $employeeId, 'A ' . T8_CERTIFICATE_TYPES[$type] . ' (' . $docNumber . ') has been generated for you.');
                t8_flash_set('success', T8_CERTIFICATE_TYPES[$type] . ' ' . $docNumber . ' created.');
                redirect(page_url('documents', ['action' => 'certificate_view', 'id' => $newId]));
            }
        }
    }
    ?>
    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', ['action' => 'certificate_new'])) ?>"><i class="fa-solid fa-arrow-left"></i> Change Type</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
    <?php endforeach; ?>

    <div class="t8-card">
        <div class="t8-card-header"><h2 class="t8-card-title">New <?= e(T8_CERTIFICATE_TYPES[$type]) ?></h2></div>

        <form method="post" action="<?= e(page_url('documents', ['action' => 'certificate_new', 'type' => $type])) ?>" novalidate>
            <?= t8_csrf_field() ?>
            <input type="hidden" name="certificate_type" value="<?= e($type) ?>">

            <div class="t8-field">
                <label class="t8-label" for="employee_id">Employee</label>
                <select class="t8-select" id="employee_id" name="employee_id" required>
                    <option value="">Select an employee…</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= e((string) $emp['id']) ?>" <?= (string) $emp['id'] === $formValues['employee_id'] ? 'selected' : '' ?>><?= e($emp['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="t8-help-text">Employee name and department are pulled automatically — nothing is retyped.</span>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="details">Purpose / Details <span class="t8-help-text">(optional)</span></label>
                <textarea class="t8-textarea" id="details" name="details" rows="3"><?= e($formValues['details']) ?></textarea>
            </div>

            <button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-check"></i> Generate Certificate</button>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>">Cancel</a>
        </form>
    </div>
    <?php
    return;
}

if ($action === 'certificate_view') {
    $id = (int) ($_GET['id'] ?? 0);
    $cert = $id ? t8_hr_certificate_fetch($pdo, $id) : null;
    if (!$cert) {
        t8_flash_set('danger', 'Certificate not found.');
        redirect(page_url('documents'));
    }
    $versions = t8_hr_versions($pdo, 'certificate', $id);
    ?>
    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <a class="t8-btn t8-btn-outline" target="_blank" href="<?= e(page_url('documents', ['action' => 'hr_print', 'type' => 'certificate', 'id' => $id])) ?>">
            <i class="fa-solid fa-print"></i> Print
        </a>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= e(T8_CERTIFICATE_TYPES[$cert['certificate_type']] ?? 'Certificate') ?> — <?= e($cert['document_number']) ?></h2>
            <span class="t8-badge <?= e(t8_hr_status_badge((string) $cert['status'])) ?>"><?= e(ucfirst((string) $cert['status'])) ?></span>
        </div>

        <div class="t8-hr-readonly-block">
            <div class="t8-hr-readonly-item"><span>Employee</span><strong><?= e($cert['employee_name']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Department</span><strong><?= e($cert['department_name'] ?? '—') ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Prepared By</span><strong><?= e($cert['prepared_by_name']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Date</span><strong><?= e(format_date((string) $cert['created_at'], 'M d, Y')) ?></strong></div>
        </div>

        <?php if (!empty($cert['details'])): ?>
            <div class="t8-field">
                <label class="t8-label">Purpose / Details</label>
                <p><?= nl2br(e((string) $cert['details'])) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($cert['status'] === 'draft' || $cert['status'] === 'pending'): ?>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <?php if ($cert['status'] === 'draft'): ?>
                    <form method="post" action="<?= e(page_url('documents', ['action' => 'certificate_status'])) ?>">
                        <?= t8_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                        <input type="hidden" name="status" value="pending">
                        <button class="t8-btn t8-btn-outline t8-btn-sm" type="submit"><i class="fa-solid fa-paper-plane"></i> Send for Approval</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= e(page_url('documents', ['action' => 'certificate_status'])) ?>">
                        <?= t8_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                        <input type="hidden" name="status" value="approved">
                        <button class="t8-btn t8-btn-success t8-btn-sm" type="submit"><i class="fa-solid fa-check"></i> Approve</button>
                    </form>
                    <form method="post" action="<?= e(page_url('documents', ['action' => 'certificate_status'])) ?>">
                        <?= t8_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                        <input type="hidden" name="status" value="rejected">
                        <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit"><i class="fa-solid fa-xmark"></i> Reject</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php elseif ($cert['status'] !== 'archived'): ?>
            <form method="post" action="<?= e(page_url('documents', ['action' => 'certificate_status'])) ?>" onsubmit="return confirm('Archive this certificate?');">
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

if ($action === 'certificate_status') {
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
        redirect(page_url('documents', ['action' => 'certificate_view', 'id' => $id]));
    }

    $cert = t8_hr_certificate_fetch($pdo, $id);
    if ($cert) {
        $pdo->prepare('UPDATE team8_certificates SET status = :status WHERE id = :id')->execute(['status' => $newStatus, 'id' => $id]);
        t8_audit_log($pdo, $currentUserId, 'certificate', $id, $newStatus);
        t8_hr_notify($pdo, (int) $cert['employee_id'], 'Your certificate ' . $cert['document_number'] . ' was marked ' . $newStatus . '.');
        t8_flash_set('success', 'Certificate ' . $newStatus . '.');
    } else {
        t8_flash_set('danger', 'Certificate not found.');
    }
    redirect(page_url('documents', ['action' => 'certificate_view', 'id' => $id]));
}
