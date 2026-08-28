<?php
/**
 * public/reservation_status_poll.php
 * DYNAMIC STATUS: small AJAX-only endpoint polled by
 * public/js/reservation.js so reservation status badges (Pending →
 * Approved → Ongoing → Completed, plus Cancelled/Rejected) and time-
 * conflict indicators stay in sync across every open reservation view
 * without the user needing to manually refresh the page.
 *
 * POST only, same auth/CSRF/JSON pattern as
 * public/notifications_action.php.
 *
 * Request:  POST ids[]=1&ids[]=2&...&csrf_token=...
 * Response: {"reservations":[{"id":1,"status":"approved","display_status":"ongoing","has_conflict":false}, ...]}
 *
 * Visibility rule: a non-admin only ever receives fresh data for
 * reservations that were already visible to them somewhere in the UI
 * - either their own (any status), or anything in the shared
 * approved/cancellation_pending/completed/cancelled pool that "All
 * Reservations" already shows everyone. This mirrors the same scoping
 * already enforced by the module's own queries; the poll endpoint
 * must not become a way to peek at another staff member's pending
 * request just by guessing an id.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db_connect.php';
require_once __DIR__ . '/../app/includes/auth_check.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/reservation_helpers.php';

header('Content-Type: application/json');

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

$ids = $_POST['ids'] ?? [];
if (!is_array($ids) || $ids === []) {
    echo json_encode(['reservations' => []]);
    exit;
}

$ids = array_values(array_unique(array_map('intval', $ids)));
$ids = array_filter($ids, static fn (int $id): bool => $id > 0);
$ids = array_slice(array_values($ids), 0, 200); // sane upper bound per poll

if ($ids === []) {
    echo json_encode(['reservations' => []]);
    exit;
}

$currentUserId = t8_current_user_id();
$isAdmin = t8_has_role('admin');

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare(
    "SELECT r.id, r.status, r.facility_id, r.user_id, r.start_time, r.end_time, r.schedule, r.expected_return_date
     FROM team8_reservations r
     WHERE r.id IN ($placeholders)"
);
$stmt->execute($ids);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$publiclyVisibleStatuses = ['approved', 'cancellation_pending', 'completed', 'cancelled'];

$result = [];
foreach ($rows as $row) {
    $isOwnRow = $currentUserId !== null && (int) $row['user_id'] === $currentUserId;
    if (!$isAdmin && !$isOwnRow && !in_array($row['status'], $publiclyVisibleStatuses, true)) {
        continue;
    }

    $displayStatus = t8_reservation_display_status($row);

    $hasConflict = false;
    if ($row['status'] === 'approved' && $row['start_time'] && $row['end_time']) {
        $endTs = strtotime((string) $row['end_time']);
        if ($endTs !== false && $endTs >= time()) {
            $hasConflict = t8_reservation_has_conflict(
                $pdo,
                (int) $row['facility_id'],
                (string) $row['start_time'],
                (string) $row['end_time'],
                (int) $row['id']
            );
        }
    }

    $result[] = [
        'id'             => (int) $row['id'],
        'status'         => (string) $row['status'],
        'display_status' => $displayStatus,
        'has_conflict'   => $hasConflict,
    ];
}

echo json_encode(['reservations' => $result]);
