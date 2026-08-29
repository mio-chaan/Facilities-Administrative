<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db_connect.php';
require_once __DIR__ . '/../app/includes/auth_check.php';
require_once __DIR__ . '/../app/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$facilityId = (int) ($_GET['facility_id'] ?? 0);
$start = trim((string) ($_GET['start_time'] ?? ''));
$end = trim((string) ($_GET['end_time'] ?? ''));
$excludeId = (int) ($_GET['exclude_id'] ?? 0);

if ($facilityId < 1 || $start === '' || $end === '' || strtotime($start) === false || strtotime($end) === false || strtotime($start) >= strtotime($end)) {
    echo json_encode(['valid' => false, 'available' => false, 'message' => 'Select a valid facility, start time, and end time.']);
    exit;
}

try {
    $facilityStmt = $pdo->prepare(
        "SELECT id, name, capacity, status, maintenance_status
         FROM team8_facilities
         WHERE id = :id LIMIT 1"
    );
    $facilityStmt->execute(['id' => $facilityId]);
    $facility = $facilityStmt->fetch(PDO::FETCH_ASSOC);

    if (!$facility || $facility['status'] !== 'active' || ($facility['maintenance_status'] ?? 'operational') === 'maintenance') {
        echo json_encode(['valid' => true, 'available' => false, 'message' => 'This facility is not currently available.']);
        exit;
    }

    $sql = "SELECT COUNT(*) FROM team8_reservations
            WHERE facility_id = :facility_id
              AND status IN ('pending', 'approved', 'cancellation_pending')
              AND start_time < :end_time AND end_time > :start_time";
    $params = ['facility_id' => $facilityId, 'start_time' => $start, 'end_time' => $end];
    if ($excludeId > 0) {
        $sql .= ' AND id != :exclude_id';
        $params['exclude_id'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $conflict = (int) $stmt->fetchColumn() > 0;

    echo json_encode([
        'valid' => true,
        'available' => !$conflict,
        'message' => $conflict
            ? 'Unavailable: this facility has an overlapping reservation.'
            : 'Available: no overlapping reservation was found.',
        'facility' => $facility['name'],
        'capacity' => (int) $facility['capacity'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['valid' => false, 'available' => false, 'message' => 'Availability could not be checked right now.']);
}
