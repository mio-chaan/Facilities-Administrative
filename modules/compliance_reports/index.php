<?php
declare(strict_types=1);

if (empty($t8ComplianceEmbedded) && current_page() !== 'compliance_reports_all') {
    $legacyAction = (string) ($_GET['action'] ?? 'list');
    if (!in_array($legacyAction, ['list', 'generate', 'review', 'approve_send'], true)) {
        $legacyAction = 'list';
    }
    $legacyParams = ['report_action' => $legacyAction];
    if (isset($_GET['id'])) {
        $legacyParams['id'] = (int) $_GET['id'];
    }
    redirect(page_url('retention', $legacyParams));
}

t8_require_role(['admin']);

$complianceAction = current_page() === 'compliance_reports_all'
    ? 'all'
    : (string) ($_GET['report_action'] ?? 'list');
$currentUserId = t8_current_user_id();
$errors = [];
$departments = $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

function t8_compliance_report_url(string $action = 'list', array $params = []): string
{
    return page_url('retention', array_merge(['report_action' => $action], $params));
}

function t8_compliance_all_reports_url(int $pageNumber = 1): string
{
    return page_url('compliance_reports_all', ['page_num' => max(1, $pageNumber)]);
}

function t8_compliance_report_date_valid(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $dateErrors = DateTimeImmutable::getLastErrors();
    return $date !== false && $date->format('Y-m-d') === $value
        && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0));
}

function t8_compliance_report_check_mandatory_documents(PDO $pdo, ?string $onlyRequiredName = null, bool $expireOnToday = true): array
{
    $requirements = MANDATORY_COMPLIANCE_DOCUMENTS;
    if ($onlyRequiredName !== null) {
        if (!array_key_exists($onlyRequiredName, $requirements)) {
            return [];
        }
        $requirements = [$onlyRequiredName => $requirements[$onlyRequiredName]];
    }

    $expirationColumnStmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name LIMIT 1'
    );
    $expirationColumnStmt->execute(['table_name' => 'team8_documents', 'column_name' => 'expiration_date']);
    $hasExpirationDate = (bool) $expirationColumnStmt->fetchColumn();
    $expirationSelect = $hasExpirationDate ? 'd.expiration_date' : 'NULL AS expiration_date';
    $results = [];

    foreach ($requirements as $requiredName => $matchingTypes) {
        $matchingTypes = is_array($matchingTypes) ? $matchingTypes : [(string) $matchingTypes];
        $placeholders = implode(',', array_fill(0, count($matchingTypes), '?'));
        $documentStmt = $pdo->prepare(
            "SELECT d.id, d.title, {$expirationSelect}
             FROM team8_documents d
             JOIN team8_document_categories c ON c.id = d.category_id
             WHERE c.name = 'Compliance'
               AND d.deleted_at IS NULL
               AND d.status = 'approved'
               AND LOWER(TRIM(d.document_type)) IN ({$placeholders})
             ORDER BY d.updated_at DESC, d.created_at DESC, d.id DESC
             LIMIT 1"
        );
        $documentStmt->execute(array_map(static fn(string $type): string => strtolower(trim($type)), $matchingTypes));
        $document = $documentStmt->fetch(PDO::FETCH_ASSOC);
        if ($document === false) {
            $results[] = [
                'name' => (string) $requiredName,
                'status' => 'missing',
                'detail' => 'Not uploaded in Document Management.',
                'document_title' => null,
                'expiration_date' => null,
            ];
            continue;
        }

        $expirationDate = $hasExpirationDate ? (string) ($document['expiration_date'] ?? '') : '';
        if ($hasExpirationDate && $expirationDate === '') {
            $results[] = [
                'name' => (string) $requiredName,
                'status' => 'expired',
                'detail' => 'No expiration date',
                'document_title' => (string) $document['title'],
                'expiration_date' => null,
            ];
            continue;
        }
        $isExpired = $expirationDate !== ''
            && ($expireOnToday ? $expirationDate <= date('Y-m-d') : $expirationDate < date('Y-m-d'));
        if ($isExpired) {
            $results[] = [
                'name' => (string) $requiredName,
                'status' => 'expired',
                'detail' => 'Expired on ' . format_date($expirationDate, 'M d, Y'),
                'document_title' => (string) $document['title'],
                'expiration_date' => $expirationDate,
            ];
            continue;
        }

        $results[] = [
            'name' => (string) $requiredName,
            'status' => 'ok',
            'detail' => (string) $document['title'] . ($expirationDate !== '' ? ' - valid through ' . format_date($expirationDate, 'M d, Y') : ''),
            'document_title' => (string) $document['title'],
            'expiration_date' => $expirationDate !== '' ? $expirationDate : null,
        ];
    }

    return $results;
}

function t8_compliance_single_check_content(array $report, array $requirement, string $preparedBy, string $recipientEmails, bool $dispatchSent): array
{
    $generatedAt = date('M d, Y g:i A');
    $period = format_date((string) $report['date_from'], 'M d, Y') . ' - ' . format_date((string) $report['date_to'], 'M d, Y');
    $department = trim((string) ($report['department_name'] ?? '')) ?: 'All Departments';
    $documentType = (string) $requirement['name'];
    $documentTitle = trim((string) ($requirement['document_title'] ?? ''));
    $documentLabel = $documentTitle !== '' ? $documentType . ' (' . $documentTitle . ')' : $documentType;
    $status = (string) $requirement['status'];
    $expirationDate = (string) ($requirement['expiration_date'] ?? '');

    if ($status === 'missing') {
        $validationText = 'Missing - Not uploaded in Document Management.';
    } elseif ($status === 'expired') {
        $validationText = $expirationDate !== ''
            ? 'Expired on ' . format_date($expirationDate, 'M d, Y') . '.'
            : 'Expired - no expiration date is recorded.';
    } else {
        $validationText = $expirationDate !== ''
            ? 'Valid through ' . format_date($expirationDate, 'M d, Y') . '.'
            : 'Valid; no expiration date applies.';
    }

    $dispatchText = $dispatchSent
        ? 'Gmail dispatch was sent to ' . $recipientEmails . '.'
        : 'Gmail dispatch is paused until this requirement is valid.';
    $summary = sprintf(
        'Report #%d (%s) covers %s for %s. Generated %s and prepared by %s. The single requirement checked was %s: %s Recipient: %s. %s',
        (int) $report['id'],
        (string) $report['report_type'],
        $period,
        $department,
        $generatedAt,
        $preparedBy,
        $documentLabel,
        $validationText,
        $recipientEmails,
        $dispatchText
    );

    $findings = ['Expiration/Validity Audit: ' . $documentLabel . ' - ' . $validationText];
    if ($status === 'ok') {
        $findings[] = 'Verification: The selected document requirement was verified on ' . $generatedAt . '; the report was sent via Gmail to ' . $recipientEmails . '.';
    } else {
        $action = $status === 'missing'
            ? 'Upload a current ' . $documentType . ' in Document Management.'
            : 'Renew or replace the ' . $documentType . ' in Document Management.';
        $findings[] = 'Action Item: ' . $action . ' Checked on ' . $generatedAt . '. Gmail dispatch is paused.';
    }

    return ['executive_summary' => $summary, 'key_findings' => $findings, 'generated_at' => $generatedAt];
}

function t8_compliance_report_findings(array $rows): array
{
    $findings = [];
    foreach ($rows as $row) {
        $normalized = strtolower(str_replace([' ', '-'], '_', (string) $row['result']));
        if (!in_array($normalized, ['compliant', 'pass', 'passed', 'complete', 'completed'], true)) {
            $details = (string) $row['record_title'] . ': ' . ucwords(str_replace('_', ' ', (string) $row['result']));
            if (!empty($row['notes'])) {
                $details .= ' - ' . (string) $row['notes'];
            }
            $findings[] = $details;
        }
    }
    if ($rows === []) {
        return ['No compliance checks were recorded for this reporting period.'];
    }
    if ($findings === []) {
        return ['All recorded compliance checks passed or were marked compliant.'];
    }
    return $findings;
}

function t8_compliance_report_pdf_html(array $report, array $findings, array $checks): string
{
    $department = (string) ($report['department_name'] ?? 'All Departments');
    $range = format_date((string) $report['date_from'], 'M d, Y') . ' - ' . format_date((string) $report['date_to'], 'M d, Y');
    $findingHtml = '';
    foreach ($findings as $finding) {
        $findingHtml .= '<li>' . nl2br(e((string) $finding)) . '</li>';
    }
    $checkRows = '';
    foreach ($checks as $check) {
        $checkRows .= '<tr><td>' . e(format_date((string) $check['check_date'], 'M d, Y')) . '</td><td>'
            . e((string) $check['record_title']) . '</td><td>' . e((string) ($check['department_name'] ?? 'Unassigned'))
            . '</td><td>' . e(ucwords(str_replace('_', ' ', (string) $check['result']))) . '</td><td>'
            . e((string) ($check['notes'] ?? '')) . '</td></tr>';
    }
    return '<!doctype html><html><head><meta charset="utf-8"><style>
        body{font-family:DejaVu Sans,sans-serif;color:#292321;font-size:10px}h1{color:#781d32;font-size:22px;margin:0 0 8px}h2{font-size:14px;color:#781d32;margin:22px 0 8px}
        .meta{color:#655b58;margin:0 0 18px}.summary{background:#f7f2f1;padding:14px;border-left:4px solid #a12b45;line-height:1.6}
        li{margin:0 0 6px;line-height:1.5}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ded8d6;padding:6px;text-align:left;vertical-align:top}th{background:#f4eded;color:#4d202b}
        </style></head><body><h1>' . e((string) $report['report_type']) . '</h1><p class="meta">' . e($department) . ' | ' . e($range) . '</p>
        <h2>Executive Summary</h2><div class="summary">' . nl2br(e((string) $report['executive_summary'])) . '</div>
        <h2>Key Findings</h2><ul>' . $findingHtml . '</ul><h2>Compliance Check Details</h2>
        <table><thead><tr><th>Date</th><th>Record</th><th>Department</th><th>Result</th><th>Notes</th></tr></thead><tbody>'
        . ($checkRows !== '' ? $checkRows : '<tr><td colspan="5">No checks were recorded.</td></tr>')
        . '</tbody></table></body></html>';
}

function t8_compliance_send_report(array $report, array $recipients, string $pdfPath, array $findings, array $attachments = []): void
{
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Email dependencies are missing. Run Composer install first.');
    }
    require_once $autoload;
    if (!defined('GMAIL_SMTP_USER') || GMAIL_SMTP_USER === ''
        || !defined('GMAIL_SMTP_APP_PASSWORD') || GMAIL_SMTP_APP_PASSWORD === '') {
        throw new RuntimeException('Gmail SMTP is not configured. Add GMAIL_SMTP_USER and GMAIL_SMTP_APP_PASSWORD to app/config/config.local.php.');
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = GMAIL_SMTP_USER;
    $mail->Password = GMAIL_SMTP_APP_PASSWORD;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom(GMAIL_SMTP_USER, 'Facilities & Administration');
    foreach ($recipients as $recipient) {
        $mail->addAddress($recipient);
    }
    $department = (string) ($report['department_name'] ?? 'All Departments');
    $range = (string) $report['date_from'] . ' - ' . (string) $report['date_to'];
    $mail->Subject = '[Compliance Report] ' . $report['report_type'] . ' — ' . $department . ' — ' . $range;
    $items = '';
    foreach ($findings as $finding) {
        $items .= '<li>' . nl2br(e((string) $finding)) . '</li>';
    }
    $mail->isHTML(true);
    $mail->Body = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#292321;line-height:1.6">'
        . '<h1 style="color:#781d32">' . e((string) $report['report_type']) . '</h1>'
        . '<p><strong>Department:</strong> ' . e($department) . '<br><strong>Date range:</strong> ' . e($range) . '</p>'
        . '<h2>Executive Summary</h2><p>' . nl2br(e((string) $report['executive_summary'])) . '</p>'
        . '<h2>Key Findings</h2><ul>' . $items . '</ul><p>The full report is attached as a PDF.</p></body></html>';
    $mail->AltBody = (string) $report['executive_summary'] . "\n\nKey Findings:\n- " . implode("\n- ", $findings);
    $mail->addAttachment($pdfPath, 'compliance-report-' . (int) $report['id'] . '.pdf');
    $uploadRoot = realpath(UPLOAD_DIR);
    if ($uploadRoot === false) {
        throw new RuntimeException('Document storage is unavailable.');
    }
    foreach ($attachments as $attachment) {
        $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) ($attachment['file_path'] ?? ''));
        $fullPath = realpath($uploadRoot . DIRECTORY_SEPARATOR . $relativePath);
        if ($fullPath === false || !str_starts_with($fullPath, $uploadRoot . DIRECTORY_SEPARATOR) || !is_file($fullPath)) {
            throw new RuntimeException('An attached supporting document is missing from storage.');
        }
        $extension = pathinfo($fullPath, PATHINFO_EXTENSION);
        $displayName = (string) ($attachment['title'] ?? basename($fullPath));
        if ($extension !== '' && !str_ends_with(strtolower($displayName), '.' . strtolower($extension))) {
            $displayName .= '.' . $extension;
        }
        $mail->addAttachment($fullPath, $displayName);
    }
    $mail->send();
}

function t8_compliance_approve_and_send(PDO $pdo, array $report, int $currentUserId, array $emails, ?string $checkedRequirement = null, ?array $singleCheckContent = null): void
{
    $findings = $singleCheckContent['key_findings'] ?? (json_decode((string) $report['key_findings'], true) ?: []);
    if ($singleCheckContent !== null) {
        $report['executive_summary'] = (string) $singleCheckContent['executive_summary'];
    }
    $checks = json_decode((string) $report['check_details'], true) ?: [];
    $attachmentStmt = $pdo->prepare(
        'SELECT d.title, v.file_path
         FROM team8_compliance_report_documents rd
         JOIN team8_documents d ON d.id = rd.document_id
         JOIN team8_document_versions v ON v.document_id = d.id AND v.version_no = d.current_version
         WHERE rd.compliance_report_id = :report_id
         ORDER BY rd.attached_at ASC, d.title ASC'
    );
    $attachmentStmt->execute(['report_id' => (int) $report['id']]);
    $emailAttachments = $attachmentStmt->fetchAll(PDO::FETCH_ASSOC);
    $pdfPath = '';

    try {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('Email/PDF dependencies are missing. Run Composer install first.');
        }
        require_once $autoload;
        $dompdf = new Dompdf\Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml(t8_compliance_report_pdf_html($report, $findings, $checks), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf = $dompdf->output();

        $documentsDirectory = UPLOAD_DIR . '/documents';
        if (!is_dir($documentsDirectory) && !mkdir($documentsDirectory, 0775, true) && !is_dir($documentsDirectory)) {
            throw new RuntimeException('Could not create the Document Management storage directory.');
        }
        $reportId = (int) $report['id'];
        $storedName = 'compliance-report-' . $reportId . '-' . bin2hex(random_bytes(4)) . '.pdf';
        $pdfPath = $documentsDirectory . '/' . $storedName;
        if (file_put_contents($pdfPath, $pdf, LOCK_EX) === false) {
            throw new RuntimeException('Could not save the generated PDF.');
        }
        $relativePath = 'documents/' . $storedName;
        $fileSize = filesize($pdfPath) ?: 0;
        $checksum = hash_file('sha256', $pdfPath) ?: null;
        $categoryStmt = $pdo->prepare('SELECT id FROM team8_document_categories WHERE LOWER(name) = LOWER(:name) LIMIT 1');
        $categoryStmt->execute(['name' => 'Compliance']);
        $categoryId = $categoryStmt->fetchColumn();

        $pdo->beginTransaction();
        $docInsert = $pdo->prepare(
            "INSERT INTO team8_documents (category_id, document_type, department_id, uploaded_by, title, file_path, current_version, status)
             VALUES (:category_id, 'Compliance Report', :department_id, :uploaded_by, :title, :file_path, 1, 'approved')"
        );
        $documentTitle = 'Compliance Report ' . str_pad((string) $reportId, 6, '0', STR_PAD_LEFT);
        $docInsert->execute([
            'category_id' => $categoryId !== false ? (int) $categoryId : null,
            'department_id' => $report['department_id'] !== null ? (int) $report['department_id'] : null,
            'uploaded_by' => $currentUserId,
            'title' => $documentTitle,
            'file_path' => $relativePath,
        ]);
        $documentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO team8_document_versions (document_id, version_no, file_path, file_size, checksum)
             VALUES (:document_id, 1, :file_path, :file_size, :checksum)'
        )->execute([
            'document_id' => $documentId,
            'file_path' => $relativePath,
            'file_size' => $fileSize,
            'checksum' => $checksum,
        ]);
        $pdo->prepare(
            "UPDATE team8_compliance_reports
             SET executive_summary = :executive_summary, key_findings = :key_findings,
                 status = 'approved', approved_by = :approved_by, approved_at = NOW(), document_id = :document_id
             WHERE id = :id AND status = 'draft'"
        )->execute([
            'executive_summary' => (string) $report['executive_summary'],
            'key_findings' => $singleCheckContent !== null
                ? json_encode($findings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                : (string) $report['key_findings'],
            'approved_by' => $currentUserId,
            'document_id' => $documentId,
            'id' => $reportId,
        ]);

        t8_compliance_send_report($report, $emails, $pdfPath, $findings, $emailAttachments);
        $recipientList = implode(', ', $emails);
        $pdo->prepare(
            'UPDATE team8_compliance_reports SET recipient_emails = :recipient_emails, email_sent_at = NOW() WHERE id = :id'
        )->execute(['recipient_emails' => $recipientList, 'id' => $reportId]);
        t8_audit_log(
            $pdo, $currentUserId, 'compliance_report', $reportId,
            'Compliance Report Approved & Issued', 'draft', 'Document #' . $documentId, true
        );
        t8_audit_log(
            $pdo, $currentUserId, 'compliance_report', $reportId,
            'Compliance Report Emailed', null,
            $report['report_type'] . ' | ' . ($report['department_name'] ?? 'All Departments')
                . ' | ' . $report['date_from'] . ' - ' . $report['date_to']
                . ' | ' . $recipientList . ' | ' . date('Y-m-d H:i:s'), true
        );
        if ($checkedRequirement !== null) {
            t8_audit_log(
                $pdo, $currentUserId, 'compliance_report', $reportId,
                'Compliance Single Requirement Checked', null, $checkedRequirement . ' | valid', true
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($pdfPath !== '' && is_file($pdfPath)) {
            @unlink($pdfPath);
        }
        throw $e;
    }
}

function t8_compliance_json_response(array $payload, int $statusCode = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

$defaultRecipient = '';
$recipientStmt = $pdo->prepare('SELECT email FROM users WHERE id = :user_id AND deleted_at IS NULL LIMIT 1');
$recipientStmt->execute(['user_id' => $currentUserId]);
$defaultRecipient = (string) ($recipientStmt->fetchColumn() ?: '');
$pickerDocuments = [];
if ($complianceAction === 'generate') {
    $pickerDocuments = $pdo->query(
        'SELECT id, title FROM team8_documents WHERE deleted_at IS NULL ORDER BY title, id'
    )->fetchAll(PDO::FETCH_ASSOC);
}
$selectedDocumentIds = [];

if ($complianceAction === 'generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $reportType = trim((string) ($_POST['report_type'] ?? 'Compliance Checks Summary'));
    $departmentId = trim((string) ($_POST['department_id'] ?? ''));
    $dateFrom = trim((string) ($_POST['date_from'] ?? ''));
    $dateTo = trim((string) ($_POST['date_to'] ?? ''));
    $postedDocumentIds = $_POST['document_ids'] ?? [];
    if (!is_array($postedDocumentIds)) {
        $errors[] = 'Select valid supporting documents.';
    } else {
        foreach ($postedDocumentIds as $postedDocumentId) {
            $documentIdValue = (string) $postedDocumentId;
            if (!ctype_digit($documentIdValue) || (int) $documentIdValue < 1) {
                $errors[] = 'Select valid supporting documents.';
                break;
            }
            $selectedDocumentIds[] = (int) $documentIdValue;
        }
        $selectedDocumentIds = array_values(array_unique($selectedDocumentIds));
    }
    $upload = $_FILES['supporting_document'] ?? ['error' => UPLOAD_ERR_NO_FILE];
    $hasSupportingUpload = is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($reportType !== 'Compliance Checks Summary') {
        $errors[] = 'Select a valid report type.';
    } elseif (!t8_compliance_report_date_valid($dateFrom) || !t8_compliance_report_date_valid($dateTo) || $dateTo < $dateFrom) {
        $errors[] = 'Choose a valid date range. The end date cannot be before the start date.';
    } elseif ($departmentId !== '' && !ctype_digit($departmentId)) {
        $errors[] = 'Select a valid department.';
    }
    if ($hasSupportingUpload) {
        $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'png', 'jpg', 'jpeg'];
        $extension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
            $errors[] = 'The supporting document upload failed. Please choose the file again.';
        } elseif ((int) ($upload['size'] ?? 0) > UPLOAD_MAX_SIZE_MB * 1024 * 1024) {
            $errors[] = 'The supporting document exceeds the ' . UPLOAD_MAX_SIZE_MB . 'MB upload limit.';
        } elseif (!in_array($extension, $allowedExtensions, true)) {
            $errors[] = 'The supporting document file type is not allowed.';
        } elseif (!function_exists('t8_validate_uploaded_file_mime')
            || !t8_validate_uploaded_file_mime((string) $upload['tmp_name'], (string) $upload['name'])) {
            $errors[] = 'The supporting document contents do not match the selected file type.';
        }
    }
    if ($selectedDocumentIds !== []) {
        $documentPlaceholders = implode(',', array_fill(0, count($selectedDocumentIds), '?'));
        $documentStmt = $pdo->prepare(
            'SELECT id FROM team8_documents WHERE deleted_at IS NULL AND id IN (' . $documentPlaceholders . ')'
        );
        $documentStmt->execute($selectedDocumentIds);
        if (count($documentStmt->fetchAll(PDO::FETCH_COLUMN)) !== count($selectedDocumentIds)) {
            $errors[] = 'One or more selected documents are no longer available.';
        }
    }

    if ($errors === []) {
        $where = 'cc.check_date BETWEEN :date_from AND :date_to';
        $params = ['date_from' => $dateFrom, 'date_to' => $dateTo];
        if ($departmentId !== '') {
            $where .= ' AND dep.id = :department_id';
            $params['department_id'] = (int) $departmentId;
        }
        $checkStmt = $pdo->prepare(
            "SELECT cc.check_date, cc.result, cc.notes, r.entity_type,
                    COALESCE(doc.title, con.title, lc.title, CONCAT('Record #', r.entity_id)) AS record_title,
                    dep.id AS department_id, dep.name AS department_name
             FROM team8_compliance_checks cc
             JOIN team8_records r ON r.id = cc.record_id
             LEFT JOIN team8_documents doc ON r.entity_type = 'document' AND doc.id = r.entity_id
             LEFT JOIN team8_contracts con ON r.entity_type = 'contract' AND con.id = r.entity_id
             LEFT JOIN team8_legal_cases lc ON r.entity_type = 'legal_case' AND lc.id = r.entity_id
             LEFT JOIN departments dep ON dep.id = COALESCE(doc.department_id, con.department_id, lc.department_id)
             WHERE {$where}
             ORDER BY cc.check_date ASC, cc.id ASC"
        );
        $checkStmt->execute($params);
        $checks = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
        $findings = t8_compliance_report_findings($checks);
        $passCount = 0;
        foreach ($checks as $check) {
            $normalized = strtolower(str_replace([' ', '-'], '_', (string) $check['result']));
            if (in_array($normalized, ['compliant', 'pass', 'passed', 'complete', 'completed'], true)) {
                $passCount++;
            }
        }
        $needsAttention = count($checks) - $passCount;
        $checkPhrase = count($checks) === 1 ? 'check was' : 'checks were';
        $reviewPhrase = $needsAttention === 1 ? 'requires review' : 'require review';
        $departmentName = 'All Departments';
        if ($departmentId !== '') {
            foreach ($departments as $department) {
                if ((int) $department['id'] === (int) $departmentId) {
                    $departmentName = (string) $department['name'];
                    break;
                }
            }
        }
        $summary = sprintf(
            '%d compliance %s recorded from %s through %s for %s. %d checks passed or were marked compliant; %d %s.',
            count($checks), $checkPhrase, $dateFrom, $dateTo, $departmentName, $passCount, $needsAttention, $reviewPhrase
        );
        $uploadedFilePath = null;
        try {
            $pdo->beginTransaction();
            if ($hasSupportingUpload) {
                $documentTitle = trim(pathinfo(basename((string) $upload['name']), PATHINFO_FILENAME));
                $documentTitle = $documentTitle !== '' ? $documentTitle : 'Supporting Document';
                $documentTitle = function_exists('mb_substr')
                    ? mb_substr($documentTitle, 0, 200, 'UTF-8')
                    : substr($documentTitle, 0, 200);
                $slug = strtolower(trim($documentTitle));
                $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
                $storedName = ($slug !== '' ? $slug : 'supporting-document') . '_v1_' . bin2hex(random_bytes(4)) . '.' . $extension;
                $documentsDirectory = UPLOAD_DIR . '/documents';
                if (!is_dir($documentsDirectory) && !mkdir($documentsDirectory, 0755, true) && !is_dir($documentsDirectory)) {
                    throw new RuntimeException('Could not create the Document Management storage directory.');
                }
                $destination = $documentsDirectory . '/' . $storedName;
                if (!move_uploaded_file((string) $upload['tmp_name'], $destination)) {
                    throw new RuntimeException('Could not store the supporting document.');
                }
                $uploadedFilePath = $destination;
                $relativePath = 'documents/' . $storedName;
                $categoryStmt = $pdo->prepare('SELECT id FROM team8_document_categories WHERE LOWER(name) = LOWER(:name) LIMIT 1');
                $categoryStmt->execute(['name' => 'Compliance']);
                $categoryId = $categoryStmt->fetchColumn();
                $documentInsert = $pdo->prepare(
                    "INSERT INTO team8_documents (category_id, document_type, department_id, uploaded_by, title, file_path, current_version, status)
                     VALUES (:category_id, 'Supporting Document', :department_id, :uploaded_by, :title, :file_path, 1, 'approved')"
                );
                $documentInsert->execute([
                    'category_id' => $categoryId !== false ? (int) $categoryId : null,
                    'department_id' => $departmentId !== '' ? (int) $departmentId : null,
                    'uploaded_by' => $currentUserId,
                    'title' => $documentTitle,
                    'file_path' => $relativePath,
                ]);
                $uploadedDocumentId = (int) $pdo->lastInsertId();
                $pdo->prepare(
                    'INSERT INTO team8_document_versions (document_id, version_no, file_path, file_size, checksum)
                     VALUES (:document_id, 1, :file_path, :file_size, :checksum)'
                )->execute([
                    'document_id' => $uploadedDocumentId,
                    'file_path' => $relativePath,
                    'file_size' => (int) $upload['size'],
                    'checksum' => hash_file('sha256', $destination) ?: null,
                ]);
                if (function_exists('t8_retention_register') && function_exists('t8_retention_fetch_for_entity')
                    && t8_retention_fetch_for_entity($pdo, 'document', $uploadedDocumentId) === null) {
                    t8_retention_register(
                        $pdo,
                        'document',
                        $uploadedDocumentId,
                        'BIR RR No. 7-2024 (EOPT Act) - financial/tax-relevant document',
                        5,
                        date('Y-m-d'),
                        $currentUserId
                    );
                    t8_audit_log($pdo, $currentUserId, 'document', $uploadedDocumentId, 'retention_registered');
                }
                $selectedDocumentIds[] = $uploadedDocumentId;
            }

            $insert = $pdo->prepare(
                "INSERT INTO team8_compliance_reports
                    (report_type, department_id, date_from, date_to, executive_summary, key_findings, check_details, generated_by)
                 VALUES (:report_type, :department_id, :date_from, :date_to, :executive_summary, :key_findings, :check_details, :generated_by)"
            );
            $insert->execute([
                'report_type' => $reportType,
                'department_id' => $departmentId !== '' ? (int) $departmentId : null,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'executive_summary' => $summary,
                'key_findings' => json_encode($findings, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'check_details' => json_encode($checks, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'generated_by' => $currentUserId,
            ]);
            $reportId = (int) $pdo->lastInsertId();
            if ($selectedDocumentIds !== []) {
                $attachStmt = $pdo->prepare(
                    'INSERT INTO team8_compliance_report_documents (compliance_report_id, document_id)
                     VALUES (:report_id, :document_id)'
                );
                foreach ($selectedDocumentIds as $selectedDocumentId) {
                    $attachStmt->execute(['report_id' => $reportId, 'document_id' => $selectedDocumentId]);
                }
            }
            t8_audit_log($pdo, $currentUserId, 'compliance_report', $reportId, 'generate', null, $reportType . ' | ' . $dateFrom . ' - ' . $dateTo);
            $pdo->commit();
            redirect(t8_compliance_report_url('review', ['id' => $reportId]));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($uploadedFilePath !== null && is_file($uploadedFilePath)) {
                @unlink($uploadedFilePath);
            }
            error_log('Compliance report generation failed: ' . $e->getMessage());
            $errors[] = 'The report could not be generated. Please check the supporting document and try again.';
        }
    }
}

if ($complianceAction === 'check_single_document') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        t8_compliance_json_response(['success' => false, 'canSend' => false, 'message' => 'POST is required.'], 405);
    }

    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input) || !t8_csrf_verify((string) ($input['csrfToken'] ?? ''))) {
        t8_compliance_json_response(['success' => false, 'canSend' => false, 'message' => 'Your session expired. Reload and try again.'], 400);
    }

    $reportId = (int) ($input['reportId'] ?? 0);
    $reportTitle = trim((string) ($input['reportTitle'] ?? ''));
    $requiredDocType = trim((string) ($input['requiredDocType'] ?? ''));
    $recipientInput = trim((string) ($input['recipientEmail'] ?? ''));
    if (!array_key_exists($requiredDocType, MANDATORY_COMPLIANCE_DOCUMENTS)) {
        t8_compliance_json_response(['success' => false, 'canSend' => false, 'message' => 'Select a configured document requirement.'], 400);
    }

    $emails = array_values(array_unique(array_filter(array_map('trim', explode(',', $recipientInput)), static fn(string $email): bool => $email !== '')));
    if ($emails === [] || count($emails) > 20) {
        t8_compliance_json_response(['success' => false, 'canSend' => false, 'message' => 'Enter between 1 and 20 recipient email addresses.'], 400);
    }
    foreach ($emails as $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            t8_compliance_json_response(['success' => false, 'canSend' => false, 'message' => 'One or more recipient addresses are invalid.'], 400);
        }
    }

    $reportStmt = $pdo->prepare(
        'SELECT cr.*, dep.name AS department_name
         FROM team8_compliance_reports cr
         LEFT JOIN departments dep ON dep.id = cr.department_id
         WHERE cr.id = :id LIMIT 1'
    );
    $reportStmt->execute(['id' => $reportId]);
    $report = $reportStmt->fetch(PDO::FETCH_ASSOC);
    if ($report === false || $report['status'] !== 'draft' || $reportTitle !== (string) $report['report_type']) {
        t8_compliance_json_response(['success' => false, 'canSend' => false, 'message' => 'This draft report is not available.'], 404);
    }

    $singleCheck = t8_compliance_report_check_mandatory_documents($pdo, $requiredDocType, false)[0] ?? null;
    if ($singleCheck === null) {
        t8_compliance_json_response(['success' => false, 'canSend' => false, 'message' => 'The selected requirement could not be checked.'], 400);
    }
    if ($singleCheck['status'] !== 'ok') {
        $singleCheckContent = t8_compliance_single_check_content(
            $report,
            $singleCheck,
            t8_current_user_name(),
            implode(', ', $emails),
            false
        );
        $status = $singleCheck['status'] === 'missing' ? 'Missing' : 'Expired';
        $detail = $status === 'Missing'
            ? 'Not uploaded in Document Management.'
            : (str_starts_with((string) $singleCheck['detail'], 'Expired on ')
                ? (string) $singleCheck['detail'] . '. Renewal required.'
                : 'No expiration date. Renewal required.');
        t8_audit_log(
            $pdo, $currentUserId, 'compliance_report', $reportId,
            'Compliance Single Requirement Checked', null, $requiredDocType . ' | ' . $status . ' | ' . $detail
        );
        t8_compliance_json_response([
            'success' => false,
            'canSend' => false,
            'failedRequirement' => ['type' => $requiredDocType, 'status' => $status, 'detail' => $detail],
            'executiveSummary' => $singleCheckContent['executive_summary'],
            'keyFindings' => $singleCheckContent['key_findings'],
        ]);
    }

    try {
        $singleCheckContent = t8_compliance_single_check_content(
            $report,
            $singleCheck,
            t8_current_user_name(),
            implode(', ', $emails),
            true
        );
        t8_compliance_approve_and_send($pdo, $report, (int) $currentUserId, $emails, $requiredDocType, $singleCheckContent);
        t8_flash_set('success', 'Compliance report successfully sent via Gmail!');
        t8_compliance_json_response([
            'success' => true,
            'canSend' => true,
            'message' => 'Compliance report successfully sent via Gmail!',
            'executiveSummary' => $singleCheckContent['executive_summary'],
            'keyFindings' => $singleCheckContent['key_findings'],
        ]);
    } catch (Throwable $e) {
        error_log('Single-document compliance report send failed: ' . $e->getMessage());
        t8_compliance_json_response([
            'success' => false,
            'canSend' => false,
            'message' => 'The report could not be sent. It remains a draft and can be retried.',
        ], 500);
    }
}

if ($complianceAction === 'approve_send') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        redirect(t8_compliance_report_url());
    }
    $reportId = (int) ($_POST['id'] ?? 0);
    $recipientInput = trim((string) ($_POST['recipient_emails'] ?? ''));
    $emails = array_values(array_unique(array_filter(array_map('trim', explode(',', $recipientInput)), static fn(string $email): bool => $email !== '')));
    if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($emails === [] || count($emails) > 20) {
        $errors[] = 'Enter between 1 and 20 recipient email addresses.';
    } else {
        foreach ($emails as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
                $errors[] = 'One or more recipient addresses are invalid.';
                break;
            }
        }
    }

    $reportStmt = $pdo->prepare(
        'SELECT cr.*, dep.name AS department_name
         FROM team8_compliance_reports cr
         LEFT JOIN departments dep ON dep.id = cr.department_id
         WHERE cr.id = :id LIMIT 1'
    );
    $reportStmt->execute(['id' => $reportId]);
    $report = $reportStmt->fetch(PDO::FETCH_ASSOC);
    if ($report === false || $report['status'] !== 'draft') {
        $errors[] = 'This report is not available for approval.';
    }

    $requirementsChecklist = [];
    $requirementsValid = true;
    $showRequirementsChecklist = false;
    if ($errors === []) {
        $requirementsChecklist = t8_compliance_report_check_mandatory_documents($pdo);
        foreach ($requirementsChecklist as $requirement) {
            if ($requirement['status'] !== 'ok') {
                $requirementsValid = false;
                break;
            }
        }
        $showRequirementsChecklist = !$requirementsValid;
    }

    if ($errors === [] && $requirementsValid) {
        try {
            t8_compliance_approve_and_send($pdo, $report, (int) $currentUserId, $emails);
            t8_flash_set('success', 'Compliance report successfully sent via Gmail!');
            redirect(t8_compliance_report_url());
        } catch (Throwable $e) {
            error_log('Compliance report email failed: ' . $e->getMessage());
            $errors[] = 'Email dispatch failed. The report remains a draft and can be retried. Check Gmail SMTP configuration and try again.';
        }
    }
    $complianceAction = 'review';
}

$report = null;
$findings = [];
$checks = [];
$attachedDocuments = [];
$recipientValue = $defaultRecipient;
if ($complianceAction === 'review') {
    $reportId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    $reportStmt = $pdo->prepare(
        'SELECT cr.*, dep.name AS department_name, u.full_name AS generated_by_name
         FROM team8_compliance_reports cr
         LEFT JOIN departments dep ON dep.id = cr.department_id
         JOIN users u ON u.id = cr.generated_by WHERE cr.id = :id LIMIT 1'
    );
    $reportStmt->execute(['id' => $reportId]);
    $report = $reportStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($report === null) {
        t8_flash_set('danger', 'Compliance report not found.');
        redirect(t8_compliance_report_url());
    }
    $findings = json_decode((string) $report['key_findings'], true) ?: [];
    $checks = json_decode((string) $report['check_details'], true) ?: [];
    $attachedStmt = $pdo->prepare(
        'SELECT d.id, d.title, v.id AS version_id, rd.attached_at
         FROM team8_compliance_report_documents rd
         JOIN team8_documents d ON d.id = rd.document_id
         JOIN team8_document_versions v ON v.document_id = d.id AND v.version_no = d.current_version
         WHERE rd.compliance_report_id = :report_id
         ORDER BY rd.attached_at ASC, d.title ASC'
    );
    $attachedStmt->execute(['report_id' => $reportId]);
    $attachedDocuments = $attachedStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recipient_emails'])) {
        $recipientValue = (string) $_POST['recipient_emails'];
    }
}

$reports = [];
$reportCount = 0;
$historyPage = 1;
$historyPageCount = 1;
if (in_array($complianceAction, ['list', 'all'], true)) {
    $reportCount = (int) $pdo->query('SELECT COUNT(*) FROM team8_compliance_reports')->fetchColumn();
    if ($complianceAction === 'all') {
        $historyPageSize = 5;
        $historyPageCount = max(1, (int) ceil($reportCount / $historyPageSize));
        $historyPage = min(max(1, (int) ($_GET['page_num'] ?? 1)), $historyPageCount);
        $historyOffset = ($historyPage - 1) * $historyPageSize;
        $historyLimit = " LIMIT {$historyPageSize} OFFSET {$historyOffset}";
    } else {
        $historyLimit = ' LIMIT 5';
    }
    $reports = $pdo->query(
        'SELECT cr.id, cr.report_type, cr.date_from, cr.date_to, cr.status,
                cr.recipient_emails, cr.email_sent_at, cr.created_at,
                dep.name AS department_name, u.full_name AS generated_by_name
         FROM team8_compliance_reports cr
         LEFT JOIN departments dep ON dep.id = cr.department_id
         JOIN users u ON u.id = cr.generated_by
         ORDER BY GREATEST(cr.created_at, cr.updated_at) DESC, cr.id DESC' . $historyLimit
    )->fetchAll(PDO::FETCH_ASSOC);
}
?>
<?php if ($complianceAction === 'all'): ?>
    <h1>All Compliance Reports</h1>
<?php elseif (!empty($t8ComplianceEmbedded)): ?>
    <h2>Compliance Reports</h2>
<?php else: ?>
    <h1>Compliance Reports</h1>
<?php endif; ?>
<p class="t8-help-text">Generate, review, approve, and distribute compliance check summaries.</p>

<?php foreach ($errors as $error): ?>
    <div class="t8-alert t8-alert-danger"><?= e($error) ?></div>
<?php endforeach; ?>

<?php if ($complianceAction === 'generate'): ?>
    <div class="t8-card">
        <div class="t8-card-header"><h2 class="t8-card-title">Generate Compliance Report</h2></div>
        <form method="post" enctype="multipart/form-data" action="<?= e(t8_compliance_report_url('generate')) ?>" class="t8-legal-form-grid">
            <?= t8_csrf_field() ?>
            <div class="t8-field"><label class="t8-label" for="report_type">Report Type</label>
                <select class="t8-select" id="report_type" name="report_type" required><option>Compliance Checks Summary</option></select></div>
            <div class="t8-field"><label class="t8-label" for="department_id">Department</label>
                <select class="t8-select" id="department_id" name="department_id"><option value="">All Departments</option>
                    <?php foreach ($departments as $department): ?><option value="<?= e((string) $department['id']) ?>"><?= e((string) $department['name']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="t8-field"><label class="t8-label" for="date_from">Date From</label>
                <input class="t8-input" type="date" id="date_from" name="date_from" value="<?= e(date('Y-m-01')) ?>" required></div>
            <div class="t8-field"><label class="t8-label" for="date_to">Date To</label>
                <input class="t8-input" type="date" id="date_to" name="date_to" value="<?= e(date('Y-m-d')) ?>" required></div>
            <fieldset class="t8-compliance-attachments t8-form-span-2" data-compliance-document-picker>
                <legend>Supporting Documents</legend>
                <div class="t8-field">
                    <label class="t8-label" for="compliance_document_search">Attach Existing Documents</label>
                    <input class="t8-input" type="search" id="compliance_document_search" placeholder="Search documents by title" autocomplete="off" data-compliance-document-search>
                </div>
                <div class="t8-compliance-document-results" data-compliance-document-results>
                    <?php if ($pickerDocuments === []): ?>
                        <span class="t8-help-text">No documents are currently available.</span>
                    <?php else: ?>
                        <?php foreach ($pickerDocuments as $document): ?>
                            <?php $pickerDocumentId = (int) $document['id']; ?>
                            <label class="t8-compliance-document-option" data-compliance-document-option data-search="<?= e(function_exists('mb_strtolower') ? mb_strtolower((string) $document['title'], 'UTF-8') : strtolower((string) $document['title'])) ?>">
                                <input type="checkbox" name="document_ids[]" value="<?= e((string) $pickerDocumentId) ?>" data-document-title="<?= e((string) $document['title']) ?>" <?= in_array($pickerDocumentId, $selectedDocumentIds, true) ? 'checked' : '' ?>>
                                <span><?= e((string) $document['title']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <span class="t8-help-text" data-compliance-no-document-match hidden>No matching documents.</span>
                </div>
                <div class="t8-compliance-selected-wrap">
                    <strong>Attached to this report</strong>
                    <ul class="t8-compliance-selected-list" data-compliance-selected-documents aria-live="polite"></ul>
                </div>
                <div class="t8-field t8-compliance-upload-field">
                    <label class="t8-label" for="supporting_document">Upload New Document</label>
                    <input class="t8-input" type="file" id="supporting_document" name="supporting_document" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.png,.jpg,.jpeg">
                    <span class="t8-help-text">Max <?= e((string) UPLOAD_MAX_SIZE_MB) ?>MB. Allowed: PDF, Word, Excel, PowerPoint, text, and image files.</span>
                </div>
            </fieldset>
            <div class="t8-form-actions"><button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-chart-column"></i> Generate Draft</button>
                <a class="t8-btn t8-btn-outline" href="<?= e(t8_compliance_report_url()) ?>">Cancel</a></div>
        </form>
    </div>
    <script>
    (function () {
        var picker = document.querySelector('[data-compliance-document-picker]');
        if (!picker) return;
        var search = picker.querySelector('[data-compliance-document-search]');
        var options = Array.prototype.slice.call(picker.querySelectorAll('[data-compliance-document-option]'));
        var selectedList = picker.querySelector('[data-compliance-selected-documents]');
        var noMatches = picker.querySelector('[data-compliance-no-document-match]');

        function renderPicker() {
            var query = search.value.trim().toLocaleLowerCase();
            var matches = 0;
            options.forEach(function (option) {
                var visible = (option.getAttribute('data-search') || '').indexOf(query) !== -1;
                option.hidden = !visible;
                if (visible) matches++;
            });
            noMatches.hidden = matches !== 0 || options.length === 0;
            selectedList.replaceChildren();
            var selected = options.map(function (option) { return option.querySelector('input'); }).filter(function (input) { return input.checked; });
            selected.forEach(function (input) {
                var item = document.createElement('li');
                var title = document.createElement('span');
                var remove = document.createElement('button');
                title.textContent = input.getAttribute('data-document-title') || '';
                remove.type = 'button';
                remove.className = 't8-btn t8-btn-outline t8-btn-sm';
                remove.textContent = 'Remove';
                remove.addEventListener('click', function () {
                    input.checked = false;
                    renderPicker();
                });
                item.append(title, remove);
                selectedList.appendChild(item);
            });
            if (selected.length === 0) {
                var empty = document.createElement('li');
                empty.textContent = 'No supporting documents selected.';
                empty.className = 't8-help-text';
                selectedList.appendChild(empty);
            }
        }

        search.addEventListener('input', renderPicker);
        options.forEach(function (option) {
            option.querySelector('input').addEventListener('change', renderPicker);
        });
        renderPicker();
    })();
    </script>
<?php elseif ($complianceAction === 'review' && $report !== null): ?>
    <?php if (!empty($showRequirementsChecklist)): ?>
        <dialog class="t8-compliance-requirements-modal" id="t8ComplianceRequirementsModal" aria-labelledby="t8ComplianceRequirementsTitle">
            <div class="t8-compliance-requirements-header">
                <div><span class="t8-compliance-requirements-eyebrow">Approval paused</span><h2 id="t8ComplianceRequirementsTitle">Requirements Checklist</h2></div>
                <button class="t8-btn t8-btn-outline t8-btn-sm" type="button" data-close-requirements>Close</button>
            </div>
            <p class="t8-help-text">Upload or renew the missing or expired compliance documents before sending this report. It remains a draft.</p>
            <ul class="t8-compliance-requirements-list">
                <?php foreach ($requirementsChecklist as $requirement): ?>
                    <li class="<?= $requirement['status'] === 'ok' ? 'is-valid' : 'is-invalid' ?>">
                        <span class="t8-compliance-requirement-indicator" aria-hidden="true"><i class="fa-solid <?= $requirement['status'] === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i></span>
                        <span><strong><?= e($requirement['name']) ?></strong><small><?= e($requirement['detail']) ?></small></span>
                        <span class="t8-compliance-requirement-status"><?= $requirement['status'] === 'ok' ? 'Valid' : e(ucfirst($requirement['status'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="t8-compliance-requirements-actions">
                <a class="t8-btn t8-btn-accent" href="<?= e(page_url('documents', ['action' => 'create'])) ?>"><i class="fa-solid fa-folder-open"></i> Go to Document Management</a>
            </div>
        </dialog>
        <script>
        (function () {
            var modal = document.getElementById('t8ComplianceRequirementsModal');
            if (!modal) return;
            var closeButton = modal.querySelector('[data-close-requirements]');
            if (closeButton) closeButton.addEventListener('click', function () { modal.close(); });
            if (typeof modal.showModal === 'function') modal.showModal();
            else modal.setAttribute('open', '');
        })();
        </script>
    <?php endif; ?>
    <div class="t8-card t8-compliance-review-card">
        <div class="t8-card-header t8-compliance-review-header">
            <h2 class="t8-card-title">Review Draft #<?= e((string) $report['id']) ?></h2>
            <span class="t8-badge <?= $report['status'] === 'approved' ? 't8-badge-approved' : 't8-badge-pending' ?> t8-compliance-review-status"><?= e(ucfirst((string) $report['status'])) ?></span>
        </div>
        <div class="t8-hr-readonly-block t8-compliance-review-meta">
            <div class="t8-hr-readonly-item"><span>Report Type</span><strong><?= e((string) $report['report_type']) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Department</span><strong><?= e((string) ($report['department_name'] ?? 'All Departments')) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Date Range</span><strong><?= e(format_date((string) $report['date_from'], 'M d, Y')) ?> - <?= e(format_date((string) $report['date_to'], 'M d, Y')) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Checks Included</span><strong><?= e((string) count($checks)) ?></strong></div>
            <div class="t8-hr-readonly-item"><span>Status</span><strong><span class="t8-badge <?= $report['status'] === 'approved' ? 't8-badge-approved' : 't8-badge-pending' ?>"><?= e(ucfirst((string) $report['status'])) ?></span></strong></div>
        </div>
        <section class="t8-compliance-review-section">
            <h3><i class="fa-solid fa-align-left" aria-hidden="true"></i> Executive Summary</h3>
            <p data-single-dynamic-summary><?= nl2br(e((string) $report['executive_summary'])) ?></p>
        </section>
        <section class="t8-compliance-review-section">
            <h3><i class="fa-solid fa-list-check" aria-hidden="true"></i> Key Findings</h3>
            <ul data-single-dynamic-findings><?php foreach ($findings as $finding): ?><li><?= nl2br(e((string) $finding)) ?></li><?php endforeach; ?></ul>
        </section>
        <section class="t8-compliance-review-section t8-compliance-review-supporting-documents">
            <h3><i class="fa-solid fa-paperclip" aria-hidden="true"></i> Supporting Documents</h3>
            <?php if ($attachedDocuments === []): ?>
                <p class="t8-help-text">No supporting documents attached.</p>
            <?php else: ?>
                <ul class="t8-compliance-review-documents">
                    <?php foreach ($attachedDocuments as $document): ?>
                        <li><a href="<?= e(page_url('documents', ['action' => 'versions', 'id' => $document['id']])) ?>"><?= e((string) $document['title']) ?></a>
                            <span><?= e(format_date((string) $document['attached_at'], 'M d, Y g:i A')) ?></span>
                            <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(page_url('documents', ['action' => 'download', 'version_id' => $document['version_id']])) ?>">Download</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <?php if ($report['status'] === 'draft'): ?>
            <section class="t8-single-document-check" data-single-document-check
                     data-report-id="<?= e((string) $report['id']) ?>"
                     data-report-title="<?= e((string) $report['report_type']) ?>"
                     data-endpoint="<?= e(t8_compliance_report_url('check_single_document')) ?>"
                     data-review-url="<?= e(t8_compliance_report_url('review', ['id' => $report['id']])) ?>"
                     data-csrf-token="<?= e(t8_csrf_token()) ?>">
                <div>
                    <h3><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Single-Document Check</h3>
                    <p class="t8-help-text">Validate one configured document and send this report if it is valid.</p>
                </div>
                <div class="t8-single-document-controls">
                    <div class="t8-field">
                        <label class="t8-label" for="single_required_document">Essential Document</label>
                        <select class="t8-select" id="single_required_document" data-single-required-document>
                            <?php foreach (MANDATORY_COMPLIANCE_DOCUMENTS as $requiredName => $_matchingTypes): ?>
                                <option value="<?= e((string) $requiredName) ?>"><?= e((string) $requiredName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="t8-btn t8-btn-outline" type="button" data-check-single-document>
                        <i class="fa-solid fa-circle-check"></i> Check Selected Document
                    </button>
                </div>
                <div class="t8-alert t8-alert-success" role="status" data-single-document-success hidden></div>
                <div class="t8-alert t8-alert-danger" role="alert" data-single-document-error hidden></div>
            </section>
            <dialog class="t8-compliance-requirements-modal t8-single-document-modal" data-single-document-modal aria-labelledby="singleDocumentModalTitle">
                <div class="t8-compliance-requirements-header">
                    <div><span class="t8-compliance-requirements-eyebrow">Approval paused</span><h2 id="singleDocumentModalTitle">APPROVAL PAUSED: SINGLE REQUIREMENT CHECK</h2></div>
                    <button class="t8-btn t8-btn-outline t8-btn-sm" type="button" data-close-single-document>Close</button>
                </div>
                <p class="t8-help-text">Reviewing essential document requirement (1 of 1 selected)</p>
                <dl class="t8-single-document-result">
                    <div><dt>Document Type</dt><dd data-single-result-type></dd></div>
                    <div><dt>Status</dt><dd data-single-result-status></dd></div>
                    <div><dt>Detail</dt><dd data-single-result-detail></dd></div>
                </dl>
                <div class="t8-compliance-requirements-actions">
                    <a class="t8-btn t8-btn-accent" href="<?= e(page_url('documents', ['action' => 'create'])) ?>"><i class="fa-solid fa-folder-open"></i> Go to Document Management</a>
                </div>
            </dialog>
            <script>
            (function () {
                var check = document.querySelector('[data-single-document-check]');
                if (!check) return;
                var button = check.querySelector('[data-check-single-document]');
                var typeSelect = check.querySelector('[data-single-required-document]');
                var success = check.querySelector('[data-single-document-success]');
                var error = check.querySelector('[data-single-document-error]');
                var modal = document.querySelector('[data-single-document-modal]');
                var closeButton = modal.querySelector('[data-close-single-document]');
                var reviewCard = check.closest('.t8-compliance-review-card');

                function applySingleCheckContent(result) {
                    var summary = reviewCard.querySelector('[data-single-dynamic-summary]');
                    var findings = reviewCard.querySelector('[data-single-dynamic-findings]');
                    if (typeof result.executiveSummary === 'string') {
                        summary.textContent = result.executiveSummary;
                    }
                    if (Array.isArray(result.keyFindings)) {
                        findings.replaceChildren();
                        result.keyFindings.forEach(function (finding) {
                            var item = document.createElement('li');
                            item.textContent = String(finding);
                            findings.appendChild(item);
                        });
                    }
                }

                closeButton.addEventListener('click', function () { modal.close(); });
                button.addEventListener('click', async function () {
                    button.disabled = true;
                    success.hidden = true;
                    error.hidden = true;
                    var recipientInput = document.getElementById('recipient_emails');
                    try {
                        var response = await fetch(check.getAttribute('data-endpoint'), {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify({
                                csrfToken: check.getAttribute('data-csrf-token'),
                                reportId: check.getAttribute('data-report-id'),
                                reportTitle: check.getAttribute('data-report-title'),
                                recipientEmail: recipientInput ? recipientInput.value.trim() : '',
                                requiredDocType: typeSelect.value
                            })
                        });
                        var result = await response.json();
                        applySingleCheckContent(result);
                        if (result.failedRequirement) {
                            var requirement = result.failedRequirement;
                            var expired = requirement.status === 'Expired';
                            modal.classList.toggle('is-expired', expired);
                            modal.classList.toggle('is-missing', !expired);
                            modal.querySelector('[data-single-result-type]').textContent = requirement.type;
                            var statusNode = modal.querySelector('[data-single-result-status]');
                            var statusIcon = document.createElement('i');
                            statusIcon.className = 'fa-solid fa-circle-exclamation';
                            statusIcon.setAttribute('aria-hidden', 'true');
                            statusNode.replaceChildren(statusIcon, document.createTextNode(' ' + requirement.status));
                            modal.querySelector('[data-single-result-detail]').textContent = requirement.detail;
                            if (typeof modal.showModal === 'function') modal.showModal();
                            else modal.setAttribute('open', '');
                        } else if (result.canSend) {
                            success.textContent = result.message || 'Compliance report successfully sent via Gmail!';
                            success.hidden = false;
                            window.setTimeout(function () { window.location.href = check.getAttribute('data-review-url'); }, 900);
                        } else {
                            error.textContent = result.message || 'The selected document could not be checked.';
                            error.hidden = false;
                        }
                    } catch (requestError) {
                        error.textContent = 'The single-document check could not be completed. Try again.';
                        error.hidden = false;
                    } finally {
                        button.disabled = false;
                    }
                });
            })();
            </script>
            <form method="post" action="<?= e(t8_compliance_report_url('approve_send')) ?>" class="t8-legal-form-grid t8-compliance-review-send-form" data-compliance-recipient-form>
                <?= t8_csrf_field() ?>
                <input type="hidden" name="id" value="<?= e((string) $report['id']) ?>">
                <div class="t8-field t8-form-span-2"><label class="t8-label" for="recipient_emails">Recipient Email(s)</label>
                    <input class="t8-input" type="text" id="recipient_emails" name="recipient_emails" value="<?= e($recipientValue) ?>"
                           placeholder="name@example.com, compliance@example.com" required
                           pattern="[^,\s@]+@[^,\s@]+\.[^,\s@]+(,\s*[^,\s@]+@[^,\s@]+\.[^,\s@]+)*"
                           title="Enter valid email addresses separated by commas.">
                    <span class="t8-help-text">Separate multiple addresses with commas. The report will remain a draft if email delivery fails.</span></div>
                <div class="t8-form-actions"><button class="t8-btn t8-btn-accent" type="submit"><i class="fa-solid fa-envelope"></i> Approve &amp; Send via Gmail</button>
                    <a class="t8-btn t8-btn-outline" href="<?= e(t8_compliance_report_url()) ?>">Cancel</a></div>
            </form>
        <?php else: ?>
            <div class="t8-alert t8-alert-success">Approved <?= e((string) $report['approved_at']) ?>.</div>
            <p><strong>Email sent to:</strong> <?= e((string) ($report['recipient_emails'] ?? 'Not sent')) ?></p>
            <?php if (!empty($report['email_sent_at'])): ?><p><strong>Email sent at:</strong> <?= e((string) $report['email_sent_at']) ?></p><?php endif; ?>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="t8-compliance-report-actions">
        <a class="t8-btn t8-btn-accent" href="<?= e(t8_compliance_report_url('generate')) ?>"><i class="fa-solid fa-plus"></i> Generate Compliance Report</a>
        <?php if ($complianceAction === 'all'): ?><a class="t8-btn t8-btn-outline" href="<?= e(page_url('retention')) ?>"><i class="fa-solid fa-arrow-left"></i> Back to Records Retention</a><?php endif; ?>
    </div>
    <div class="t8-card"><div class="t8-card-header"><h2 class="t8-card-title"><?= $complianceAction === 'all' ? 'All Reports' : 'Report History' ?></h2></div>
        <?php if ($reports === []): ?><div class="t8-empty-state"><i class="fa-solid fa-chart-pie" aria-hidden="true"></i><strong>No compliance reports yet</strong><span>Generate a report from recorded compliance checks.</span></div>
        <?php else: ?><div class="t8-table-wrap"><table class="t8-table"><thead><tr><th>Report</th><th>Department</th><th>Date Range</th><th>Status</th><th>Emailed</th><th>Recipient(s)</th><th>Email Sent</th><th>Actions</th></tr></thead><tbody>
            <?php foreach ($reports as $item): ?><tr>
                <td><?= e((string) $item['report_type']) ?><br><span class="t8-table-subtext">#<?= e((string) $item['id']) ?></span></td>
                <td><?= e((string) ($item['department_name'] ?? 'All Departments')) ?></td>
                <td><?= e(format_date((string) $item['date_from'], 'M d, Y')) ?> - <?= e(format_date((string) $item['date_to'], 'M d, Y')) ?></td>
                <td><span class="t8-badge <?= $item['status'] === 'approved' ? 't8-badge-approved' : 't8-badge-pending' ?>"><?= e(ucfirst((string) $item['status'])) ?></span></td>
                <td><?= !empty($item['email_sent_at']) ? 'Yes' : 'No' ?></td>
                <td><?= e((string) ($item['recipient_emails'] ?? '—')) ?></td>
                <td><?= !empty($item['email_sent_at']) ? e(format_date((string) $item['email_sent_at'], 'M d, Y g:i A')) : '—' ?></td>
                <td><a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(t8_compliance_report_url('review', ['id' => $item['id']])) ?>"><i class="fa-solid fa-eye"></i> <?= $item['status'] === 'draft' ? 'Review' : 'View' ?></a></td>
            </tr><?php endforeach; ?>
        </tbody></table></div>
            <?php if ($complianceAction === 'list' && $reportCount > 5): ?>
                <div class="t8-compliance-view-all"><a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(t8_compliance_all_reports_url()) ?>">View All Reports (<?= e((string) $reportCount) ?>)</a></div>
            <?php endif; ?>
            <?php if ($complianceAction === 'all' && $historyPageCount > 1): ?>
                <nav id="t8CompliancePagination" class="t8-pagination" aria-label="Compliance report pages">
                    <?php if ($historyPage > 1): ?>
                        <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(t8_compliance_all_reports_url($historyPage - 1)) ?>">Previous</a>
                    <?php endif; ?>
                    <span class="t8-help-text">Page <?= e((string) $historyPage) ?> of <?= e((string) $historyPageCount) ?></span>
                    <?php if ($historyPage < $historyPageCount): ?>
                        <a class="t8-btn t8-btn-outline t8-btn-sm" href="<?= e(t8_compliance_all_reports_url($historyPage + 1)) ?>">Next</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>