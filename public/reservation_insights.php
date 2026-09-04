<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db_connect.php';
require_once __DIR__ . '/../app/includes/auth_check.php';
require_once __DIR__ . '/../app/includes/permissions.php';
require_once __DIR__ . '/../app/includes/ai_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
t8_require_role(['admin']);

try {
    $summary = [
        'period' => 'last 30 days',
        'status_counts' => [],
        'top_facilities' => [],
        'peak_hours' => [],
    ];

    $statusRows = $pdo->query(
        "SELECT status, COUNT(*) AS total
         FROM team8_reservations
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY status"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($statusRows as $row) {
        $summary['status_counts'][(string) $row['status']] = (int) $row['total'];
    }

    $facilityRows = $pdo->query(
        "SELECT f.name, COUNT(*) AS total
         FROM team8_reservations r
         JOIN team8_facilities f ON f.id = r.facility_id
         WHERE r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY r.facility_id, f.name
         ORDER BY total DESC
         LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($facilityRows as $row) {
        $summary['top_facilities'][] = ['name' => $row['name'], 'reservations' => (int) $row['total']];
    }

    $hourRows = $pdo->query(
        "SELECT HOUR(COALESCE(start_time, schedule)) AS booking_hour, COUNT(*) AS total
         FROM team8_reservations
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
           AND COALESCE(start_time, schedule) IS NOT NULL
         GROUP BY booking_hour
         ORDER BY total DESC
         LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($hourRows as $row) {
        $summary['peak_hours'][] = ['hour' => (int) $row['booking_hour'], 'reservations' => (int) $row['total']];
    }

    $prompt = 'Analyze this aggregated reservation summary. Identify one or two useful trends and one practical operational recommendation. Do not invent facts. Keep the answer under 80 words. Data: ' . json_encode($summary, JSON_UNESCAPED_SLASHES);
    $insight = defined('GEMINI_API_KEY') && GEMINI_API_KEY !== ''
        ? t8_ai_chat([
            ['role' => 'system', 'content' => 'You provide concise facilities reservation insights using only supplied aggregate data.'],
            ['role' => 'user', 'content' => $prompt],
        ])
        : 'AI insights are not configured. Review the reservation trend and facility utilization cards for current patterns.';

    echo json_encode(['insight' => $insight, 'summary' => $summary]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Reservation insights are temporarily unavailable.']);
}
