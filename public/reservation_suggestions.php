<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db_connect.php';
require_once __DIR__ . '/../app/includes/auth_check.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/ai_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$facilityId = (int) ($_GET['facility_id'] ?? 0);
$start = trim((string) ($_GET['start_time'] ?? ''));
$end = trim((string) ($_GET['end_time'] ?? ''));

if ($facilityId < 1 || strtotime($start) === false || strtotime($end) === false || strtotime($start) >= strtotime($end)) {
    echo json_encode(['suggestions' => 'Choose a valid facility and time range first.']);
    exit;
}

try {
    $facilityStmt = $pdo->prepare("SELECT name FROM team8_facilities WHERE id = :id AND status = 'active' AND COALESCE(maintenance_status, 'operational') <> 'maintenance' LIMIT 1");
    $facilityStmt->execute(['id' => $facilityId]);
    $facilityName = (string) ($facilityStmt->fetchColumn() ?: 'the selected facility');

    $stmt = $pdo->prepare(
        "SELECT start_time, end_time, schedule, expected_return_date
         FROM team8_reservations
         WHERE facility_id = :facility_id
           AND status IN ('pending', 'approved', 'cancellation_pending')
           AND COALESCE(end_time, schedule, expected_return_date) >= NOW()
           AND COALESCE(start_time, schedule, expected_return_date) < DATE_ADD(NOW(), INTERVAL 14 DAY)
         ORDER BY COALESCE(start_time, schedule, expected_return_date) ASC
         LIMIT 50"
    );
    $stmt->execute(['facility_id' => $facilityId]);
    $occupied = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $occupied[] = ($row['start_time'] ?: $row['schedule'] ?: $row['expected_return_date']) . ' to ' . ($row['end_time'] ?: $row['expected_return_date'] ?: 'end of day');
    }

    $availabilityData = $occupied === [] ? 'No existing bookings in the next 14 days.' : implode('; ', $occupied);
    $fallback = 'Try another time on the same day, or choose a date within the next 14 days with no existing booking.';
    if (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '' && function_exists('t8_ai_chat')) {
        $reply = t8_ai_chat([
            ['role' => 'system', 'content' => 'Suggest up to three concise alternative dates or times for a facility reservation. Use only the supplied availability data. Do not invent availability. Reply in plain text, under 60 words.'],
            ['role' => 'user', 'content' => 'Facility: ' . $facilityName . '. Requested window: ' . $start . ' to ' . $end . '. Existing availability records for the next 14 days: ' . $availabilityData],
        ]);
        echo json_encode(['suggestions' => $reply]);
    } else {
        echo json_encode(['suggestions' => $fallback]);
    }
} catch (Throwable $e) {
    echo json_encode(['suggestions' => 'Try another time on the same day, or choose a later date.']);
}
