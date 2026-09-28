<?php
/**
 * modules/documents/hr/certificate.php
 * Handles: certificate_new (type picker when no ?type=, then GET
 *          form / POST create, admin only), certificate_view (GET),
 *          certificate_status (POST approve/reject/archive).
 *
 * Employee information is resolved from normalized certificate recipient
 * rows at view/print time (see t8_hr_certificate_fetch()).
 */

declare(strict_types=1);

if (in_array($action, ['certificate_new', 'certificate_status'], true)) {
    t8_require_role(['admin']);
}

if ($action === 'certificate_new') {
    $type = (string) ($_GET['type'] ?? $_POST['certificate_type'] ?? '');

    if (!array_key_exists($type, T8_CERTIFICATE_TYPES)) {
        ?>
        <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>"><i class="fa-solid fa-arrow-left"></i> Back to Document Management</a>
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
    $employeeValues = [];
    $recipientSelection = ['labels' => []];
    $formValues = ['details' => ''];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $employeeValues = isset($_POST['employees']) && is_array($_POST['employees']) ? array_values($_POST['employees']) : [];
        $formValues = [
            'details'     => trim((string) ($_POST['details'] ?? '')),
        ];

        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            $errors[] = 'Your session expired. Please try again.';
        } else {
            $recipientSelection = t8_hr_certificate_recipient_selection($pdo, $employeeValues);
            if ($recipientSelection['error'] !== null) { $errors[] = $recipientSelection['error']; }

            if (!$errors) {
                $docNumber = t8_hr_generate_doc_number($pdo, 'CERT-' . strtoupper(substr($type, 0, 3)), 'team8_certificates');
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO team8_certificates (document_number, certificate_type, employee_id, prepared_by, details, status)
                         VALUES (:document_number, :certificate_type, :employee_id, :prepared_by, :details, "approved")'
                    );
                    $stmt->execute([
                        'document_number'  => $docNumber,
                        'certificate_type' => $type,
                        'employee_id'      => (int) $recipientSelection['rows'][0]['employee_id'],
                        'prepared_by'      => $currentUserId,
                        'details'          => $formValues['details'] !== '' ? $formValues['details'] : null,
                    ]);
                    $newId = (int) $pdo->lastInsertId();
                    $recipientStmt = $pdo->prepare(
                        'INSERT INTO team8_certificate_recipients (certificate_id, employee_id)
                         VALUES (:certificate_id, :employee_id)'
                    );
                    foreach ($recipientSelection['rows'] as $recipient) {
                        $recipientStmt->execute(['certificate_id' => $newId, 'employee_id' => $recipient['employee_id']]);
                    }
                    $pdo->commit();
                } catch (Throwable $exception) {
                    $pdo->rollBack();
                    throw $exception;
                }
                t8_audit_log($pdo, $currentUserId, 'certificate', $newId, 'create');
                foreach ($recipientSelection['rows'] as $recipient) {
                    t8_hr_notify($pdo, (int) $recipient['employee_id'], 'A ' . T8_CERTIFICATE_TYPES[$type] . ' (' . $docNumber . ') has been approved for you.');
                }
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
                <span class="t8-label" id="employees-label">Employees</span>
                <div class="t8-recipient-picker" data-recipient-picker data-empty-label="Select employees">
                    <button class="t8-input t8-recipient-trigger" type="button" aria-haspopup="true" aria-expanded="false" aria-labelledby="employees-label" data-recipient-trigger>
                        <span data-recipient-summary><?= $employeeValues === [] ? 'Select employees' : e(implode(', ', $recipientSelection['labels'] ?? [])) ?></span>
                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="t8-recipient-panel" data-recipient-panel hidden>
                        <input class="t8-input t8-recipient-search" type="search" placeholder="Search employees..." aria-label="Search employees" data-recipient-search>
                        <?php foreach ($employees as $employee): ?>
                            <label class="t8-recipient-option" data-recipient-option data-recipient-label="<?= e(strtolower($employee['full_name'])) ?>">
                                <input type="checkbox" name="employees[]" value="<?= e((string) $employee['id']) ?>" data-recipient-checkbox <?= in_array((string) $employee['id'], array_map('strval', $employeeValues), true) ? 'checked' : '' ?>>
                                <span><?= e($employee['full_name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <span class="t8-help-text">Select one or more employees. Each employee will receive a personalized certificate.</span>
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

    $recipientMatch = t8_hr_certificate_recipient_fetch($pdo, $id, (int) ($currentUserId ?? 0));
    $certificateAccessRow = [
        'uploaded_by' => (int) ($cert['prepared_by'] ?? $currentUserId ?? 0),
        'owner_id' => (int) ($cert['prepared_by'] ?? $currentUserId ?? 0),
        'employee_id' => (int) ($cert['employee_id'] ?? 0),
        'recipient_employee_id' => $currentUserId,
        'department_id' => isset($cert['department_id']) ? (int) $cert['department_id'] : null,
    ];
    $isCertificateAuthorized = $isAdmin || $recipientMatch !== null || t8_document_can_access(
        $certificateAccessRow,
        (int) ($currentUserId ?? 0),
        $isAdmin,
        $pdo,
        'view',
        ['department_id' => $_SESSION['department_id'] ?? null]
    );
    if (!$isCertificateAuthorized) {
        http_response_code(403);
        echo '<div class="t8-alert t8-alert-danger">403 — You are not authorized to view this certificate.</div>';
        exit;
    }
    $versions = t8_hr_versions($pdo, 'certificate', $id);
    ?>
    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <?php foreach ($cert['recipients'] as $recipient): ?>
            <a class="t8-btn t8-btn-outline" target="_blank" href="<?= e(page_url('documents', ['action' => 'hr_print', 'type' => 'certificate', 'id' => $id, 'employee_id' => $recipient['employee_id']])) ?>">
                <i class="fa-solid fa-print"></i> Print <?= e($recipient['employee_name']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title"><?= e(T8_CERTIFICATE_TYPES[$cert['certificate_type']] ?? 'Certificate') ?> — <?= e($cert['document_number']) ?></h2>
            <span class="t8-badge <?= e(t8_hr_status_badge((string) $cert['status'])) ?>"><?= e(ucfirst((string) $cert['status'])) ?></span>
        </div>

        <div class="t8-hr-readonly-block">
            <div class="t8-hr-readonly-item"><span>Employees</span><strong><?= e(implode(', ', $cert['recipient_labels'])) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Prepared By</span><strong><?= e($cert['prepared_by_name']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Date</span><strong><?= e(format_date((string) $cert['created_at'], 'M d, Y')) ?></strong></div>
        </div>

        <?php if (!empty($cert['details'])): ?>
            <div class="t8-field">
                <label class="t8-label">Purpose / Details</label>
                <p><?= nl2br(e((string) $cert['details'])) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($cert['status'] === 'pending'): ?>
            <form class="t8-hr-decision-form" method="post" action="<?= e(page_url('documents', ['action' => 'certificate_status'])) ?>">
                <?= t8_csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                <textarea class="t8-textarea" name="rejection_reason" rows="3" placeholder="Enter reason for rejection..."></textarea>
                <div class="t8-hr-decision-actions">
                    <button class="t8-btn t8-btn-success t8-btn-sm" type="submit" name="status" value="approved"><i class="fa-solid fa-check"></i> Approve</button>
                    <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit" name="status" value="rejected"><i class="fa-solid fa-xmark"></i> Reject</button>
                </div>
            </form>
        <?php elseif ($cert['status'] !== 'archived'): ?>
            <form method="post" action="<?= e(page_url('documents', ['action' => 'certificate_status'])) ?>" onsubmit="return confirm('Archive this certificate?');">
                <?= t8_csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                <input type="hidden" name="status" value="archived">
                <button class="t8-btn t8-btn-outline t8-btn-sm" type="submit"><i class="fa-solid fa-box-archive"></i> Archive</button>
            </form>
        <?php endif; ?>
        <?php if ($cert['status'] === 'rejected'): ?>
            <div class="t8-field" style="margin-top: var(--t8-space-4);">
                <label class="t8-label">Rejection Reason</label>
                <p><?= nl2br(e((string) ($cert['rejection_reason'] ?? '—'))) ?></p>
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
    $rejectionReason = trim((string) ($_POST['rejection_reason'] ?? ''));
    if (!in_array($newStatus, T8_HR_STATUSES, true)) {
        t8_flash_set('danger', 'Invalid status.');
        redirect(page_url('documents', ['action' => 'certificate_view', 'id' => $id]));
    }
    if ($newStatus === 'rejected' && $rejectionReason === '') {
        t8_flash_set('danger', 'A rejection reason is required.');
        redirect(page_url('documents', ['action' => 'certificate_view', 'id' => $id]));
    }

    $cert = t8_hr_certificate_fetch($pdo, $id);
    if ($cert) {
        $pdo->prepare('UPDATE team8_certificates SET status = :status, rejection_reason = :reason WHERE id = :id')
            ->execute(['status' => $newStatus, 'reason' => $newStatus === 'rejected' ? $rejectionReason : null, 'id' => $id]);
        t8_audit_log($pdo, $currentUserId, 'certificate', $id, $newStatus);
        $notificationMessage = 'Your certificate ' . $cert['document_number'] . ' was marked ' . $newStatus . '.';
        if ($newStatus === 'rejected') {
            $notificationMessage .= ' Reason: ' . $rejectionReason;
        }
        foreach ($cert['recipients'] as $recipient) {
            t8_hr_notify(
                $pdo,
                (int) $recipient['employee_id'],
                $notificationMessage,
                page_url('documents', ['action' => 'certificate_view', 'id' => $id])
            );
        }
        t8_flash_set('success', 'Certificate ' . $newStatus . '.');
    } else {
        t8_flash_set('danger', 'Certificate not found.');
    }
    redirect(page_url('documents', ['action' => 'certificate_view', 'id' => $id]));
}
