<?php
/**
 * modules/documents/hr/router.php
 * Dispatches every HR-document ?action= to its controller partial.
 * Required from modules/documents/index.php, which already has $pdo,
 * $currentUserId, $isAdmin, $action, $errors in scope - every partial
 * below shares that same scope (standard PHP nested require).
 */

declare(strict_types=1);

switch ($action) {
    case 'incident_report_new':
    case 'incident_report_view':
    case 'incident_report_status':
        require __DIR__ . '/incident_report.php';
        break;

    case 'nte_new':
    case 'nte_view':
    case 'nte_status':
        require __DIR__ . '/nte.php';
        break;

    case 'explanation_new':
    case 'explanation_view':
    case 'explanation_review':
        require __DIR__ . '/explanation.php';
        break;

    case 'memorandum_new':
    case 'memorandum_edit':
    case 'memorandum_view':
    case 'memorandum_status':
        require __DIR__ . '/memorandum.php';
        break;

    case 'certificate_new':
    case 'certificate_view':
    case 'certificate_status':
        require __DIR__ . '/certificate.php';
        break;

    case 'hr_print':
        require __DIR__ . '/print.php';
        break;

    default:
        http_response_code(404);
        echo '<div class="t8-alert t8-alert-danger">404 — Unknown document action.</div>';
        break;
}
