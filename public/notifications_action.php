<?php
/**
 * notifications_action.php
 * DASHBOARD UPDATE: small AJAX-only endpoint behind the bell popover
 * (templates/navbar.php + public/js/dashboard.js). Handles two POST
 * actions:
 *   action=mark_read  &id=123   -> marks one notification read (owner-scoped)
 *   action=mark_all             -> marks every unread notification read
 *
 * Follows the same bootstrap order as public/logout.php and returns
 * JSON, same shape as modules/assistant/index.php's endpoint.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db_connect.php';
require_once __DIR__ . '/../app/includes/auth_check.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/notifications.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

if (!t8_csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['error' => 'Your session expired. Please refresh the page.']);
    exit;
}

$userId = t8_current_user_id();
$action = (string) ($_POST['action'] ?? '');

if ($action === 'mark_read') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0 || $userId === null) {
        echo json_encode(['error' => 'Invalid notification.']);
        exit;
    }
    $ok = t8_mark_notification_read($pdo, $userId, $id);
    echo json_encode(['ok' => $ok, 'unread' => t8_unread_notification_count($pdo, $userId)]);
    exit;
}

if ($action === 'mark_all') {
    if ($userId === null) {
        echo json_encode(['error' => 'Not signed in.']);
        exit;
    }
    $ok = t8_mark_all_notifications_read($pdo, $userId);
    echo json_encode(['ok' => $ok, 'unread' => t8_unread_notification_count($pdo, $userId)]);
    exit;
}

echo json_encode(['error' => 'Unknown action.']);
