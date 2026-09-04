<?php
/**
 * modules/documents/hr/nte.php
 * Handles: nte_new (picker when no incident_id, then GET form / POST
 *          create, admin only), nte_view (GET), nte_status (POST,
 *          admin only, approve/reject/archive).
 *
 * Employee / department / incident summary are pulled via JOIN from
 * the source incident report (team8_incident_reports), never
 * re-entered or trusted from $_POST — the admin only supplies
 * Deadline and Remarks, per the task spec.
 */

declare(strict_types=1);

if ($action === 'nte_new') {
    t8_require_role(['admin']);

    $incidentId = (int) ($_GET['incident_id'] ?? ($_POST['incident_id'] ?? 0));

    if (!$incidentId) {
        // No source incident selected yet — show a picker of incident
        // reports that don't already have an NTE.
        $candidates = $pdo->query(
            "SELECT ir.id, ir.document_number, ir.incident_type, ir.incident_date, u.full_name AS employee_name
             FROM team8_incident_reports ir
             JOIN users u ON u.id = ir.employee_id
             WHERE ir.status IN ('pending','approved')
               AND NOT EXISTS (SELECT 1 FROM team8_notice_to_explain n WHERE n.incident_report_id = ir.id)
             ORDER BY ir.created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>"><i class="fa-solid fa-arrow-left"></i> Back to Document Management</a>
        </div>
        <div class="t8-card">
            <div class="t8-card-header"><h2 class="t8-card-title">Select an Incident Report</h2></div>
            <p class="t8-help-text">A Notice To Explain is generated from an existing incident report.</p>
            <?php if ($candidates === []): ?>
                <div class="t8-empty">No incident reports are available to generate a notice from right now.</div>
            <?php else: ?>
                <div class="t8-table-wrap">
                    <table class="t8-table">
                        <thead><tr><th>Report #</th><th>Employee</th><th>Type</th><th>Date</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($candidates as $c): ?>
                                <tr>
                                    <td class="t8-table-ref"><?= e($c['document_number']) ?></td>
                                    <td><?= e($c['employee_name']) ?></td>
                                    <td><?= e($c['incident_type']) ?></td>
                                    <td><?= e(format_date((string) $c['incident_date'], 'M d, Y')) ?></td>
                                    <td><a class="t8-btn t8-btn-accent t8-btn-sm" href="<?= e(page_url('documents', ['action' => 'nte_new', 'incident_id' => $c['id']])) ?>">Select</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return;
    }

    $incident = t8_hr_incident_report_fetch($pdo, $incidentId);
    if (!$incident) {
        t8_flash_set('danger', 'Incident report not found.');
        redirect(page_url('documents', ['action' => 'nte_new']));
    }
    if (t8_hr_nte_for_incident($pdo, $incidentId)) {
        t8_flash_set('danger', 'A notice to explain already exists for that incident report.');
        redirect(page_url('documents', ['action' => 'incident_report_view', 'id' => $incidentId]));
    }

    $formValues = ['deadline' => date('Y-m-d', strtotime('+5 days')), 'remarks' => ''];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $formValues = [
            'deadline' => trim((string) ($_POST['deadline'] ?? '')),
            'remarks'  => trim((string) ($_POST['remarks'] ?? '')),
        ];

        if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
            $errors[] = 'Your session expired. Please try again.';
        } else {
            if ($formValues['deadline'] === '' || strtotime($formValues['deadline']) === false) {
                $errors[] = 'A valid deadline is required.';
            }

            if (!$errors) {
                $docNumber = t8_hr_generate_doc_number($pdo, 'NTE', 'team8_notice_to_explain');
                $stmt = $pdo->prepare(
                    'INSERT INTO team8_notice_to_explain (document_number, incident_report_id, employee_id, prepared_by, status, deadline, remarks)
                     VALUES (:document_number, :incident_report_id, :employee_id, :prepared_by, "pending", :deadline, :remarks)'
                );
                $stmt->execute([
                    'document_number'    => $docNumber,
                    'incident_report_id' => $incidentId,
                    'employee_id'        => $incident['employee_id'],
                    'prepared_by'        => $currentUserId,
                    'deadline'           => $formValues['deadline'],
                    'remarks'            => $formValues['remarks'] !== '' ? $formValues['remarks'] : null,
                ]);
                $newId = (int) $pdo->lastInsertId();

                t8_audit_log($pdo, $currentUserId, 'nte', $newId, 'create');
                t8_hr_notify($pdo, (int) $incident['employee_id'], 'A Notice To Explain (' . $docNumber . ') has been issued to you. Deadline: ' . format_date($formValues['deadline'], 'M d, Y') . '.');
                t8_flash_set('success', 'Notice To Explain ' . $docNumber . ' generated.');
                redirect(page_url('documents', ['action' => 'nte_view', 'id' => $newId]));
            }
        }
    }
    ?>
    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4);">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', ['action' => 'incident_report_view', 'id' => $incidentId])) ?>">
            <i class="fa-solid fa-arrow-left"></i> Back to Incident Report
        </a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
    <?php endforeach; ?>

    <div class="t8-card">
        <div class="t8-card-header"><h2 class="t8-card-title">Generate Notice To Explain</h2></div>

        <div class="t8-hr-readonly-block">
            <div class="t8-hr-readonly-item"><span>Employee</span><strong><?= e($incident['employee_name']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Department</span><strong><?= e($incident['department_name'] ?? '—') ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Incident Date</span><strong><?= e(format_date((string) $incident['incident_date'], 'M d, Y')) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Source Report</span><strong><?= e($incident['document_number']) ?></strong></div>
        </div>
        <div class="t8-field">
            <label class="t8-label">Incident Summary</label>
            <p class="t8-help-text" style="white-space:pre-wrap;"><?= nl2br(e((string) $incident['description'])) ?></p>
        </div>

        <form method="post" action="<?= e(page_url('documents', ['action' => 'nte_new', 'incident_id' => $incidentId])) ?>" novalidate>
            <?= t8_csrf_field() ?>
            <input type="hidden" name="incident_id" value="<?= e((string) $incidentId) ?>">

            <div class="t8-field">
                <label class="t8-label" for="deadline">Deadline to Respond</label>
                <input class="t8-input" type="date" id="deadline" name="deadline" value="<?= e($formValues['deadline']) ?>" required>
            </div>
            <div class="t8-field">
                <label class="t8-label" for="remarks">Remarks <span class="t8-help-text">(optional)</span></label>
                <textarea class="t8-textarea" id="remarks" name="remarks" rows="3"><?= e($formValues['remarks']) ?></textarea>
            </div>

            <button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-check"></i> Generate Notice</button>
            <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents', ['action' => 'incident_report_view', 'id' => $incidentId])) ?>">Cancel</a>
        </form>
    </div>
    <?php
    return;
}

if ($action === 'nte_view') {
    $id = (int) ($_GET['id'] ?? 0);
    $nte = $id ? t8_hr_nte_fetch($pdo, $id) : null;
    if (!$nte) {
        t8_flash_set('danger', 'Notice To Explain not found.');
        redirect(page_url('documents'));
    }
    if (!$isAdmin && (int) $nte['employee_id'] !== $currentUserId) {
        http_response_code(403);
        echo '<div class="t8-alert t8-alert-danger">403 — You do not have permission to view this notice.</div>';
        return;
    }

    $explanation = t8_hr_explanation_for_nte($pdo, $id);
    $versions = t8_hr_versions($pdo, 'nte', $id);
    $isOwner = (int) $nte['employee_id'] === $currentUserId;
    ?>
    <div class="t8-card-header" style="margin-bottom: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
        <a class="t8-btn t8-btn-outline" href="<?= e(page_url('documents')) ?>"><i class="fa-solid fa-arrow-left"></i> Back</a>
        <a class="t8-btn t8-btn-outline" target="_blank" href="<?= e(page_url('documents', ['action' => 'hr_print', 'type' => 'nte', 'id' => $id])) ?>">
            <i class="fa-solid fa-print"></i> Print
        </a>
        <?php if ($isOwner && !$explanation): ?>
            <a class="t8-btn t8-btn-accent" href="<?= e(page_url('documents', ['action' => 'explanation_new', 'nte_id' => $id])) ?>">
                <i class="fa-solid fa-pen"></i> Submit Explanation
            </a>
        <?php elseif ($explanation): ?>
            <a class="t8-btn t8-btn-accent" href="<?= e(page_url('documents', ['action' => 'explanation_view', 'id' => $explanation['id']])) ?>">
                <i class="fa-solid fa-eye"></i> View Explanation
            </a>
        <?php endif; ?>
    </div>

    <div class="t8-card">
        <div class="t8-card-header">
            <h2 class="t8-card-title">Notice To Explain — <?= e($nte['document_number']) ?></h2>
            <span class="t8-badge <?= e(t8_hr_status_badge((string) $nte['status'])) ?>"><?= e(ucfirst((string) $nte['status'])) ?></span>
        </div>

        <div class="t8-hr-readonly-block">
            <div class="t8-hr-readonly-item"><span>Employee</span><strong><?= e($nte['employee_name']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Prepared By</span><strong><?= e($nte['prepared_by_name']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Source Report</span><strong><?= e($nte['incident_number']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Deadline</span><strong><?= e(format_date((string) $nte['deadline'], 'M d, Y')) ?></strong></div>
        </div>

        <table class="t8-table" style="margin-top: var(--t8-space-4);">
            <tbody>
                <tr><th style="width:220px;">Incident Date</th><td><?= e(format_date((string) $nte['incident_date'], 'M d, Y')) ?></td></tr>
                <tr><th>Incident Type</th><td><?= e((string) $nte['incident_type']) ?></td></tr>
                <tr><th>Incident Summary</th><td><?= nl2br(e((string) $nte['incident_description'])) ?></td></tr>
                <tr><th>Remarks</th><td><?= e((string) ($nte['remarks'] ?? '—')) ?></td></tr>
            </tbody>
        </table>

        <?php if ($isAdmin && $nte['status'] === 'pending'): ?>
            <div style="margin-top: var(--t8-space-4); display:flex; gap:8px; flex-wrap:wrap;">
                <form method="post" action="<?= e(page_url('documents', ['action' => 'nte_status'])) ?>">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <input type="hidden" name="status" value="approved">
                    <button class="t8-btn t8-btn-success t8-btn-sm" type="submit"><i class="fa-solid fa-check"></i> Approve / Issue</button>
                </form>
                <form method="post" action="<?= e(page_url('documents', ['action' => 'nte_status'])) ?>">
                    <?= t8_csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $id) ?>">
                    <input type="hidden" name="status" value="rejected">
                    <button class="t8-btn t8-btn-danger t8-btn-sm" type="submit"><i class="fa-solid fa-xmark"></i> Reject</button>
                </form>
            </div>
        <?php elseif ($isAdmin && $nte['status'] !== 'archived'): ?>
            <form method="post" action="<?= e(page_url('documents', ['action' => 'nte_status'])) ?>" style="margin-top: var(--t8-space-4);"
                  onsubmit="return confirm('Archive this notice?');">
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

if ($action === 'nte_status') {
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
        redirect(page_url('documents', ['action' => 'nte_view', 'id' => $id]));
    }

    $nte = t8_hr_nte_fetch($pdo, $id);
    if ($nte) {
        $pdo->prepare('UPDATE team8_notice_to_explain SET status = :status WHERE id = :id')
            ->execute(['status' => $newStatus, 'id' => $id]);
        t8_audit_log($pdo, $currentUserId, 'nte', $id, $newStatus);
        t8_hr_notify($pdo, (int) $nte['employee_id'], 'Your Notice To Explain ' . $nte['document_number'] . ' was marked ' . $newStatus . '.');
        t8_flash_set('success', 'Notice To Explain ' . $newStatus . '.');
    } else {
        t8_flash_set('danger', 'Notice To Explain not found.');
    }
    redirect(page_url('documents', ['action' => 'nte_view', 'id' => $id]));
}
