<?php
/**
 * modules/documents/hr/incident_report.php
 * Handles: incident_report_new (GET form / POST create),
 *          incident_report_view (GET read-only view + Print + admin actions),
 *          incident_report_status (POST approve/reject/archive, admin only).
 *
 * SECURITY: employee_id / prepared_by / department_id are resolved
 * ONLY via t8_hr_current_employee($pdo), which reads the trusted
 * session user id and joins users/departments. Nothing here ever
 * reads $_POST['employee_id'], $_POST['department'], etc. — even a
 * tampered request cannot change who the report is about.
 */

declare(strict_types=1);

if ($action === 'incident_report_new') {
    $employee = t8_hr_current_employee($pdo);
    $formValues = [
        'incident_date'     => date('Y-m-d'),
        'incident_time'     => date('H:i'),
        'incident_location' => '',
        'incident_type'     => '',
        'description'       => '',
        'witness'           => '',
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $formValues = [
            'incident_date'     => trim((string) ($_POST['incident_date'] ?? '')),
            'incident_time'     => trim((string) ($_POST['incident_time'] ?? '')),
            'incident_location' => trim((string) ($_POST['incident_location'] ?? '')),
            'incident_type'     => trim((string) ($_POST['incident_type'] ?? '')),
            'description'       => trim((string) ($_POST['description'] ?? '')),
            'witness'           => trim((string) ($_POST['witness'] ?? '')),
        ];

        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            $errors[] = 'Your session expired. Please try again.';
        } else {
            if ($formValues['incident_date'] === '' || strtotime($formValues['incident_date']) === false) {
                $errors[] = 'A valid incident date is required.';
            }
            if ($formValues['incident_time'] === '') {
                $errors[] = 'Incident time is required.';
            }
            if ($formValues['incident_location'] === '') {
                $errors[] = 'Incident location is required.';
            }
            if (!in_array($formValues['incident_type'], T8_INCIDENT_TYPES, true)) {
                $errors[] = 'Please select a valid incident type.';
            }
            if ($formValues['description'] === '') {
                $errors[] = 'Description is required.';
            }

            $attachmentPath = null;
            if (!$errors && !empty($_FILES['attachment']['name'])) {
                try {
                    $attachmentPath = t8_hr_store_attachment($_FILES['attachment'], 'incident');
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }

            if (!$errors) {
                $docNumber = t8_hr_generate_doc_number($pdo, 'IR', 'team8_incident_reports');
                $stmt = $pdo->prepare(
                    'INSERT INTO team8_incident_reports
                        (document_number, employee_id, prepared_by, department_id, status,
                         incident_date, incident_time, incident_location, incident_type, description, witness, attachment_path)
                     VALUES
                        (:document_number, :employee_id, :prepared_by, :department_id, "pending",
                         :incident_date, :incident_time, :incident_location, :incident_type, :description, :witness, :attachment_path)'
                );
                $stmt->execute([
                    'document_number'   => $docNumber,
                    'employee_id'       => $employee['employee_id'],
                    'prepared_by'       => $employee['employee_id'],
                    'department_id'     => $employee['department_id'],
                    'incident_date'     => $formValues['incident_date'],
                    'incident_time'     => $formValues['incident_time'],
                    'incident_location' => $formValues['incident_location'],
                    'incident_type'     => $formValues['incident_type'],
                    'description'       => $formValues['description'],
                    'witness'           => $formValues['witness'] !== '' ? $formValues['witness'] : null,
                    'attachment_path'   => $attachmentPath,
                ]);
                $newId = (int) $pdo->lastInsertId();

                t8_audit_log($pdo, $currentUserId, 'incident_report', $newId, 'create');
                t8_hr_notify_admins($pdo, 'New incident report ' . $docNumber . ' filed by ' . $employee['full_name'] . '.');
                t8_flash_set('success', 'Incident report ' . $docNumber . ' submitted.');
                redirect(page_url('documents', ['action' => 'incident_report_view', 'id' => $newId]));
            }
        }
    }
    ?>
    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>">
            <i class="fa-solid fa-arrow-left"></i> Back to Templates
        </a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
    <?php endforeach; ?>

    <div class="t8-card">
        <div class="t8-card-header"><h2 class="t8-card-title">New Incident Report</h2></div>

        <div class="t8-hr-readonly-block">
            <div class="t8-hr-readonly-item"><span>Employee ID</span><strong>#<?= e((string) $employee['employee_id']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Employee Name</span><strong><?= e($employee['full_name']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Department</span><strong><?= e($employee['department_name']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Position</span><strong><?= e($employee['position']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Prepared By</span><strong><?= e($employee['full_name']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Date / Time Filed</span><strong><?= e(date('M d, Y g:i A')) ?></strong></div>
        </div>
        <p class="t8-help-text">The fields above are taken directly from your account and cannot be edited.</p>

        <form method="post" action="<?= e(page_url('documents', ['action' => 'incident_report_new'])) ?>" enctype="multipart/form-data" novalidate>
            <?= t8_csrf_field() ?>

            <div class="t8-reservation-datetime">
                <div class="t8-field">
                    <label class="t8-label" for="incident_date">Incident Date</label>
                    <input class="t8-input" type="date" id="incident_date" name="incident_date" value="<?= e($formValues['incident_date']) ?>" required>
                </div>
                <div class="t8-field">
                    <label class="t8-label" for="incident_time">Incident Time</label>
                    <input class="t8-input" type="time" id="incident_time" name="incident_time" value="<?= e($formValues['incident_time']) ?>" required>
                </div>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="incident_location">Incident Location</label>
                <input class="t8-input" type="text" id="incident_location" name="incident_location" value="<?= e($formValues['incident_location']) ?>" required>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="incident_type">Incident Type</label>
                <select class="t8-select" id="incident_type" name="incident_type" required>
                    <option value="">Select a type…</option>
                    <?php foreach (T8_INCIDENT_TYPES as $type): ?>
                        <option value="<?= e($type) ?>" <?= $type === $formValues['incident_type'] ? 'selected' : '' ?>><?= e($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="description">Description</label>
                <textarea class="t8-textarea" id="description" name="description" rows="4" required><?= e($formValues['description']) ?></textarea>
            </div>

            <div class="t8-field">
                <label class="t8-label" for="witness">Witness <span class="t8-help-text">(optional)</span></label>
                <input class="t8-input" type="text" id="witness" name="witness" value="<?= e($formValues['witness']) ?>">
            </div>

            <div class="t8-field">
                <label class="t8-label" for="attachment">Attachment <span class="t8-help-text">(optional)</span></label>
                <input class="t8-input" type="file" id="attachment" name="attachment">
                <span class="t8-help-text">Max <?= e((string) UPLOAD_MAX_SIZE_MB) ?>MB. PDF, Word, Excel, or image.</span>
            </div>

            <button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-check"></i> Submit Report</button>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>">Cancel</a>
        </form>
    </div>
    <?php
    return;
}

if ($action === 'incident_report_view') {
    $id = (int) ($_GET['id'] ?? 0);
    $report = $id ? t8_hr_incident_report_fetch($pdo, $id) : null;
    if (!$report) {
        t8_flash_set('danger', 'Incident report not found.');
        redirect(page_url('documents'));
    }
    if (!$isAdmin && (int) $report['employee_id'] !== $currentUserId) {
        http_response_code(403);
        echo '<div class="t8-alert t8-alert-danger">403 — You do not have permission to view this report.</div>';
        return;
    }

    $existingNte = t8_hr_nte_for_incident($pdo, $id);
    $versions = t8_hr_versions($pdo, 'incident_report', $id);
    ?>
    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <a class="t8-btn t8-btn-outline" target="_blank" href="<?= e(page_url('documents', ['action' => 'hr_print', 'type' => 'incident_report', 'id' => $id])) ?>">
            <i class="fa-solid fa-print"></i> Print
        </a>
        <?php if ($isAdmin && $report['status'] === 'pending' && !$existingNte): ?>
            <a class="t8-btn t8-btn-accent" href="<?= e(page_url('documents', ['action' => 'nte_new', 'incident_id' => $id])) ?>">
                <i class="fa-solid fa-file-circle-question"></i> Generate NTE
            </a>
        <?php elseif ($existingNte): ?>
            <a class="t8-btn t8-btn-accent" href="<?= e(page_url('documents', ['action' => 'nte_view', 'id' => $existingNte['id']])) ?>">
                <i class="fa-solid fa-eye"></i> View NTE (<?= e($existingNte['document_number']) ?>)
            </a>
        <?php endif; ?>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Incident Report — <?= e($report['document_number']) ?></h2>
            <span class="t8-badge <?= e(t8_hr_status_badge((string) $report['status'])) ?>"><?= e(ucfirst((string) $report['status'])) ?></span>
        </div>

        <div class="t8-hr-readonly-block">
            <div class="t8-hr-readonly-item"><span>Employee</span><strong><?= e($report['employee_name']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Department</span><strong><?= e($report['department_name'] ?? '—') ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Prepared By</span><strong><?= e($report['prepared_by_name']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Filed</span><strong><?= e(format_date((string) $report['created_at'], 'M d, Y g:i A')) ?></strong></div>
        </div>

        <table class="t8-table" style="margin-top: var(--t8-space-4);">
            <tbody>
                <tr><th style="width:220px;">Incident Date</th><td><?= e(format_date((string) $report['incident_date'], 'M d, Y')) ?></td></tr>
                <tr><th>Incident Time</th><td><?= e((string) $report['incident_time']) ?></td></tr>
                <tr><th>Location</th><td><?= e((string) $report['incident_location']) ?></td></tr>
                <tr><th>Type</th><td><?= e((string) $report['incident_type']) ?></td></tr>
                <tr><th>Description</th><td><?= nl2br(e((string) $report['description'])) ?></td></tr>
                <tr><th>Witness</th><td><?= e((string) ($report['witness'] ?? '—')) ?></td></tr>
                <tr>
                    <th>Attachment</th>
                    <td>
                        <?php if (!empty($report['attachment_path'])): ?>
                            <a href="<?= e(asset('uploads/' . $report['attachment_path'])) ?>" target="_blank"><i class="fa-solid fa-paperclip"></i> View attachment</a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php if ($isAdmin && $report['status'] === 'pending'): ?>
            <div style="margin-top: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
                <form method="post" action="<?= e(page_url('documents', ['action' => 'incident_report_status'])) ?>">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <input type="hidden" name="status" value="approved">
                    <button class="t8-btn t8-btn-success t8-btn-sm" type="submit"><i class="fa-solid fa-check"></i> Approve</button>
                </form>
                <form method="post" action="<?= e(page_url('documents', ['action' => 'incident_report_status'])) ?>">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <input type="hidden" name="status" value="rejected">
                    <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit"><i class="fa-solid fa-xmark"></i> Reject</button>
                </form>
            </div>
        <?php elseif ($isAdmin && $report['status'] !== 'archived'): ?>
            <form method="post" action="<?= e(page_url('documents', ['action' => 'incident_report_status'])) ?>" style="margin-top: var(--t8-space-4);"
                  onsubmit="return confirm('Archive this incident report?');">
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

if ($action === 'incident_report_status') {
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
    if (!in_array($newStatus, ['approved', 'rejected', 'archived'], true)) {
        t8_flash_set('danger', 'Invalid status.');
        redirect(page_url('documents', ['action' => 'incident_report_view', 'id' => $id]));
    }

    $report = t8_hr_incident_report_fetch($pdo, $id);
    if ($report) {
        $pdo->prepare('UPDATE team8_incident_reports SET status = :status WHERE id = :id')
            ->execute(['status' => $newStatus, 'id' => $id]);
        t8_audit_log($pdo, $currentUserId, 'incident_report', $id, $newStatus);
        t8_hr_notify($pdo, (int) $report['employee_id'], 'Your incident report ' . $report['document_number'] . ' was marked ' . $newStatus . '.');
        t8_flash_set('success', 'Incident report ' . $newStatus . '.');
    } else {
        t8_flash_set('danger', 'Incident report not found.');
    }
    redirect(page_url('documents', ['action' => 'incident_report_view', 'id' => $id]));
}
