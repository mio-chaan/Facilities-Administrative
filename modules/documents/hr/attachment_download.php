<?php
declare(strict_types=1);

$type = (string) ($_GET['type'] ?? '');
$id = (int) ($_GET['id'] ?? 0);
$path = null;
$name = 'attachment';

if ($type === 'incident_report') {
    $row = t8_hr_incident_report_fetch($pdo, $id);
    if ($row && ($isAdmin || (int) $row['employee_id'] === $currentUserId)) {
        $path = $row['attachment_path'];
        $name = $row['document_number'] . '-attachment';
    }
} elseif ($type === 'explanation') {
    $row = t8_hr_explanation_fetch($pdo, $id);
    if ($row && ($isAdmin || (int) $row['employee_id'] === $currentUserId)) {
        $path = $row['attachment_path'];
        $name = $row['nte_number'] . '-explanation';
    }
}

if (!$path || !is_string($path) || str_contains($path, '..') || str_contains($path, '\\')) {
    http_response_code(404);
    exit('File not found.');
}

$filePath = UPLOAD_DIR . '/' . ltrim($path, '/');
$realUploadDir = realpath(UPLOAD_DIR);
$realFilePath = realpath($filePath);
if ($realUploadDir === false || $realFilePath === false || !is_file($realFilePath)
    || !str_starts_with($realFilePath, $realUploadDir . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('File not found.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$extension = strtolower(pathinfo($realFilePath, PATHINFO_EXTENSION));
$downloadName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name . '.' . $extension) ?: 'attachment.' . $extension;
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string) filesize($realFilePath));
readfile($realFilePath);
exit;