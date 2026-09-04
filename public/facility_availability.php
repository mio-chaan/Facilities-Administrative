<?php
/**
 * public/facility_availability.php
 * CAPACITY VALIDATION: small read-only AJAX endpoint used by the
 * reservation form (public/js/reservation.js) to fetch a facility's
 * CURRENT available quantity the moment it's selected (or whenever
 * the facility dropdown changes), so the Quantity/Participants inputs
 * can be capped with an up-to-date max="" attribute instead of only
 * the value known at page load.
 *
 * This is a convenience/UX layer only. The authoritative check still
 * happens server-side in t8_reservation_validate() /
 * t8_reservation_committed_quantity() (see modules/reservation/index.php
 * and app/includes/reservation_helpers.php) on actual submit, so
 * disabling JS or tampering with the max="" attribute can never bypass
 * the real limit.
 *
 * GET only (read-only, no state change) - same auth bootstrap as the
 * other small JSON endpoints in this folder.
 *
 * Request:  GET ?facility_id=5&exclude_id=42   (exclude_id optional, used when editing)
 * Response: {"facility_id":5,"facility_type":"Equipment","capacity":10,"committed":3,"available":7}
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db_connect.php';
require_once __DIR__ . '/../app/includes/auth_check.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/reservation_helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$facilityId = (int) ($_GET['facility_id'] ?? 0);
$excludeId = isset($_GET['exclude_id']) && $_GET['exclude_id'] !== '' ? (int) $_GET['exclude_id'] : null;

if ($facilityId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid facility.']);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT id, name, facility_type, capacity FROM team8_facilities WHERE id = :id AND status = 'active' LIMIT 1"
);
$stmt->execute(['id' => $facilityId]);
$facility = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$facility) {
    http_response_code(404);
    echo json_encode(['error' => 'Facility not found or no longer active.']);
    exit;
}

$committed = t8_reservation_committed_quantity($pdo, $facilityId, $excludeId);
$available = max(0, (int) $facility['capacity'] - $committed);

echo json_encode([
    'facility_id'   => (int) $facility['id'],
    'facility_type' => $facility['facility_type'],
    'capacity'      => (int) $facility['capacity'],
    'committed'     => $committed,
    'available'     => $available,
]);
