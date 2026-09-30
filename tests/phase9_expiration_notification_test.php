<?php

declare(strict_types=1);

if (!defined('APP_URL')) {
    define('APP_URL', '');
}

require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/retention_helpers.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$expiredDate = date('Y-m-d', strtotime('-2 days'));
$expiringDate = date('Y-m-d', strtotime('+10 days'));
$activeDate = date('Y-m-d', strtotime('+90 days'));
$today = date('Y-m-d');

$assert(t8_document_expiration_state($expiredDate) === 'expired', 'Documents past their expiration date should report as expired.');
$assert(t8_document_expiration_state($expiringDate) === 'expiring_soon', 'Documents within 30 days should report as expiring soon.');
$assert(t8_document_expiration_state($activeDate) === 'active', 'Documents beyond 30 days should remain active.');

$params = [];
$expiredPredicate = t8_document_expiration_filter_sql('d', 'expired', $params);
$assert(str_contains($expiredPredicate, "d.deleted_at IS NULL"), 'Expired filters must exclude deleted documents.');
$assert(str_contains($expiredPredicate, "d.status = 'approved'"), 'Expired filters must exclude pending documents.');
$assert(str_contains($expiredPredicate, 'd.expiration_date IS NOT NULL') && str_contains($expiredPredicate, 'CURDATE()'), 'Expired filters should constrain by expiration_date and today.');

$params = [];
$expiringSoonPredicate = t8_document_expiration_filter_sql('d', 'expiring_soon', $params);
$assert(str_contains($expiringSoonPredicate, "d.deleted_at IS NULL"), 'Expiring-soon filters must exclude deleted documents.');
$assert(str_contains($expiringSoonPredicate, "d.status = 'approved'"), 'Expiring-soon filters must exclude pending documents.');
$assert(str_contains($expiringSoonPredicate, 'd.expiration_date IS NOT NULL') && str_contains($expiringSoonPredicate, 'DATE_ADD'), 'Expiring-soon filters should include the upcoming window.');

$params = [];
$activePredicate = t8_document_expiration_filter_sql('d', 'active', $params);
$assert(str_contains($activePredicate, "d.deleted_at IS NULL") && str_contains($activePredicate, "d.status = 'approved'"), 'Active filters must use the same live approved-document scope.');
$assert(str_contains($activePredicate, 'd.expiration_date IS NULL OR DATE(d.expiration_date) > DATE_ADD(CURDATE(), INTERVAL 30 DAY)'), 'Active documents must exclude the 30-day expiring-soon window.');
$assert(t8_document_expiration_state($expiredDate) !== 'active' && t8_document_expiration_state($expiredDate) !== 'expiring_soon', 'Approved expired documents must belong only to Expired.');
$assert(t8_document_expiration_state($expiringDate) !== 'active' && t8_document_expiration_state($expiringDate) !== 'expired', 'Approved expiring-soon documents must belong only to Expiring Soon.');
$assert(t8_document_expiration_state($today) === 'expiring_soon', 'A document expiring today belongs to Expiring Soon, not Active.');

// Capture the notification dispatch to verify the approval-flow contract
// without relying on an external database or notifications table.
class T8Phase9NotificationPdo extends PDO
{
    public function __construct() {}
}

$notifications = [];
$notificationCreated = t8_document_notify_approval(
    new T8Phase9NotificationPdo(),
    ['uploaded_by' => 73, 'title' => 'Safety Certificate', 'status' => 'pending'],
    451,
    'approved',
    static function (PDO $pdo, int $recipient, string $message, string $targetUrl) use (&$notifications): void {
        $notifications[] = compact('recipient', 'message', 'targetUrl');
    }
);
$assert($notificationCreated, 'An approval transition must create an uploader notification.');
$assert(count($notifications) === 1, 'Approval must create one uploader notification.');
$assert($notifications[0]['recipient'] === 73, 'Approval notification recipient must be uploaded_by.');
$assert($notifications[0]['targetUrl'] === page_url('documents', ['action' => 'versions', 'id' => 451]), 'Approval notification must target the document Versions page.');

// Phase 9 must not alter the established document retention workflow.
$retentionRule = t8_retention_disposal_rule('document');
$assert($retentionRule === ['two_step' => false, 'dual_control' => false, 'archive_only_default' => false], 'Document retention disposal rules must remain unchanged.');
$assert(t8_retention_compute_disposition('2026-09-25', 5) === '2031-09-25', 'Document retention scheduling behavior must remain unchanged.');

echo "Phase 9 expiration and notification checks passed.\n";
