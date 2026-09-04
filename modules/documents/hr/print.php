<?php
/**
 * modules/documents/hr/print.php
 * Standalone printable view for any HR generated document
 * (?action=hr_print&type=...&id=...). Renders its OWN complete HTML
 * document — no navbar/sidebar/app chrome — by discarding whatever
 * header.php/navbar.php already wrote into the output buffer that
 * public/index.php opened, then echoing a clean page and exiting
 * (same "raw response, then exit" pattern the existing 'download'
 * action in this module already uses).
 */

declare(strict_types=1);

$type = (string) ($_GET['type'] ?? '');
$id = (int) ($_GET['id'] ?? 0);

$docNumber = '';
$title = '';
$bodyHtml = '';
$preparedByName = '';
$statusValue = '';

switch ($type) {
    case 'incident_report':
        $row = t8_hr_incident_report_fetch($pdo, $id);
        if (!$row || (!$isAdmin && (int) $row['employee_id'] !== $currentUserId)) {
            http_response_code(404);
            exit('Document not found.');
        }
        $docNumber = $row['document_number'];
        $title = 'Incident Report';
        $preparedByName = $row['prepared_by_name'];
        $statusValue = $row['status'];
        $bodyHtml = '
            <table class="print-table">
                <tr><th>Employee</th><td>' . e($row['employee_name']) . '</td></tr>
                <tr><th>Department</th><td>' . e((string) ($row['department_name'] ?? '—')) . '</td></tr>
                <tr><th>Incident Date</th><td>' . e(format_date((string) $row['incident_date'], 'M d, Y')) . '</td></tr>
                <tr><th>Incident Time</th><td>' . e((string) $row['incident_time']) . '</td></tr>
                <tr><th>Location</th><td>' . e((string) $row['incident_location']) . '</td></tr>
                <tr><th>Type</th><td>' . e((string) $row['incident_type']) . '</td></tr>
                <tr><th>Witness</th><td>' . e((string) ($row['witness'] ?? '—')) . '</td></tr>
            </table>
            <h3>Description</h3>
            <p>' . nl2br(e((string) $row['description'])) . '</p>';
        break;

    case 'nte':
        $row = t8_hr_nte_fetch($pdo, $id);
        if (!$row || (!$isAdmin && (int) $row['employee_id'] !== $currentUserId)) {
            http_response_code(404);
            exit('Document not found.');
        }
        $docNumber = $row['document_number'];
        $title = 'Notice To Explain';
        $preparedByName = $row['prepared_by_name'];
        $statusValue = $row['status'];
        $bodyHtml = '
            <table class="print-table">
                <tr><th>Employee</th><td>' . e($row['employee_name']) . '</td></tr>
                <tr><th>Source Incident Report</th><td>' . e($row['incident_number']) . '</td></tr>
                <tr><th>Incident Date</th><td>' . e(format_date((string) $row['incident_date'], 'M d, Y')) . '</td></tr>
                <tr><th>Deadline to Respond</th><td>' . e(format_date((string) $row['deadline'], 'M d, Y')) . '</td></tr>
            </table>
            <h3>Incident Summary</h3>
            <p>' . nl2br(e((string) $row['incident_description'])) . '</p>'
            . (!empty($row['remarks']) ? '<h3>Remarks</h3><p>' . nl2br(e((string) $row['remarks'])) . '</p>' : '');
        break;

    case 'memorandum':
        if (!$isAdmin) {
            http_response_code(404);
            exit('Document not found.');
        }
        $row = t8_hr_memorandum_fetch($pdo, $id);
        if (!$row) {
            http_response_code(404);
            exit('Document not found.');
        }
        $docNumber = $row['document_number'];
        $title = $row['kind'] === 'warning_letter' ? 'Warning Letter' : 'Memorandum';
        $preparedByName = $row['prepared_by_name'];
        $statusValue = $row['status'];
        $bodyHtml = '
            <table class="print-table">
                <tr><th>Title</th><td>' . e($row['title']) . '</td></tr>
                <tr><th>Recipients</th><td>' . e($row['recipients']) . '</td></tr>
            </table>
            <h3>Content</h3>
            <p>' . nl2br(e((string) $row['content'])) . '</p>'
            . (!empty($row['remarks']) ? '<h3>Remarks</h3><p>' . nl2br(e((string) $row['remarks'])) . '</p>' : '');
        break;

    case 'certificate':
        if (!$isAdmin) {
            http_response_code(404);
            exit('Document not found.');
        }
        $row = t8_hr_certificate_fetch($pdo, $id);
        if (!$row) {
            http_response_code(404);
            exit('Document not found.');
        }
        $docNumber = $row['document_number'];
        $title = T8_CERTIFICATE_TYPES[$row['certificate_type']] ?? 'Certificate';
        $preparedByName = $row['prepared_by_name'];
        $statusValue = $row['status'];
        $bodyHtml = '
            <div class="print-certificate">
                <p>This is to certify that</p>
                <h2>' . e($row['employee_name']) . '</h2>
                <p>of the ' . e((string) ($row['department_name'] ?? '—')) . ' department</p>
                <p>' . nl2br(e((string) ($row['details'] ?? 'is issued this certificate in good standing.'))) . '</p>
            </div>';
        break;

    default:
        http_response_code(404);
        exit('Unknown document type.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e($docNumber) ?> · <?= e($title) ?> · <?= e(APP_NAME) ?></title>
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; color: #241111; max-width: 800px; margin: 40px auto; padding: 0 24px; }
    .print-header { display:flex; align-items:center; justify-content:space-between; border-bottom: 3px solid #B22222; padding-bottom: 16px; margin-bottom: 24px; }
    .print-header h1 { font-size: 1.3rem; margin: 0; color: #B22222; }
    .print-header .print-doc-no { font-family: monospace; font-size: 0.85rem; color: #666; }
    .print-title { text-align: center; margin: 24px 0; }
    .print-title h2 { margin: 0 0 4px; text-transform: uppercase; letter-spacing: 0.05em; }
    .print-title .print-status { font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #8a5c10; }
    .print-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    .print-table th, .print-table td { text-align: left; padding: 8px 10px; border: 1px solid #ece4e2; font-size: 0.9rem; }
    .print-table th { width: 220px; background: #F8F6F3; }
    .print-certificate { text-align: center; padding: 40px 0; }
    .print-certificate h2 { font-size: 1.8rem; margin: 12px 0; color: #B22222; }
    .print-meta { display:flex; justify-content: space-between; margin-top: 40px; font-size: 0.82rem; color: #555; }
    .print-signatures { display:flex; justify-content: space-between; margin-top: 60px; }
    .print-signature { width: 45%; text-align: center; }
    .print-signature .line { border-top: 1px solid #241111; margin-top: 48px; padding-top: 6px; font-size: 0.82rem; }
    .print-actions { text-align: center; margin-bottom: 24px; }
    @media print { .print-actions { display: none; } body { margin: 0; padding: 0 24px; } }
</style>
</head>
<body>
    <div class="print-actions">
        <button onclick="window.print();">Print this document</button>
    </div>

    <div class="print-header">
        <h1><?= e(APP_NAME) ?></h1>
        <div class="print-doc-no">Doc No. <?= e($docNumber) ?></div>
    </div>

    <div class="print-title">
        <h2><?= e($title) ?></h2>
        <div class="print-status">Status: <?= e(ucfirst($statusValue)) ?></div>
    </div>

    <?= $bodyHtml ?>

    <div class="print-meta">
        <span>Prepared By: <?= e($preparedByName) ?></span>
        <span>Generated: <?= e(date('M d, Y g:i A')) ?></span>
    </div>

    <div class="print-signatures">
        <div class="print-signature"><div class="line"><?= e($preparedByName) ?><br>Prepared By</div></div>
        <div class="print-signature"><div class="line">Signature Over Printed Name<br>Approved By</div></div>
    </div>
</body>
</html>
<?php
exit;
