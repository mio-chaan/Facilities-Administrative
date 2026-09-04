<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/db_connect.php';
require_once __DIR__ . '/../app/includes/auth_check.php';
require_once __DIR__ . '/../app/includes/helpers.php';
require_once __DIR__ . '/../app/includes/permissions.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$query = trim((string) ($_GET['q'] ?? ''));
if ($query === '') {
    echo json_encode(['results' => []]);
    exit;
}

$like = '%' . $query . '%';
$results = [];

$addResults = static function (array &$target, string $module, array $rows): void {
    foreach ($rows as $row) {
        $target[] = [
            'module' => $module,
            'title' => (string) ($row['title'] ?? 'Record'),
            'details' => (string) ($row['details'] ?? ''),
            'url' => (string) ($row['url'] ?? ''),
        ];
    }
};

$search = static function (string $sql, array $params = []) use ($pdo): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
};

$facilityRows = $search(
    "SELECT id, name AS title,
                 CONCAT(COALESCE(facility_type, 'Facility'), ' | ', location, ' | Status: ',
                     CASE WHEN status = 'archived' THEN 'Archived' WHEN maintenance_status = 'maintenance' THEN 'Maintenance' ELSE 'Available' END,
                   ' | Cap: ', capacity) AS details
     FROM team8_facilities
             WHERE name LIKE :q OR location LIKE :q OR facility_type LIKE :q OR status LIKE :q OR maintenance_status LIKE :q OR CAST(capacity AS CHAR) LIKE :q
     ORDER BY name LIMIT 8",
    ['q' => $like]
);
foreach ($facilityRows as &$row) {
    $row['url'] = page_url('facilities', t8_has_role('admin') ? ['action' => 'edit', 'id' => $row['id']] : []);
}
unset($row);
$addResults($results, 'Facilities', $facilityRows);

$reservationWhere = "(f.name LIKE :q OR f.facility_type LIKE :q OR r.status LIKE :q OR r.event_category LIKE :q OR r.department LIKE :q OR r.description LIKE :q OR r.remarks LIKE :q OR r.start_time LIKE :q OR r.schedule LIKE :q OR CAST(r.id AS CHAR) LIKE :q)";
$reservationParams = ['q' => $like];
if (!t8_has_role('admin')) {
    $reservationWhere .= ' AND r.user_id = :user_id';
    $reservationParams['user_id'] = t8_current_user_id();
}
$reservationRows = $search(
    "SELECT r.id, f.name AS facility_name, f.facility_type, r.status, r.event_category, r.start_time, r.schedule
     FROM team8_reservations r JOIN team8_facilities f ON f.id = r.facility_id
     WHERE {$reservationWhere}
     ORDER BY r.created_at DESC LIMIT 8",
    $reservationParams
);
foreach ($reservationRows as &$row) {
    $row['title'] = 'Reservation #' . $row['id'] . ' | ' . $row['facility_name'];
    $schedule = $row['start_time'] ?: $row['schedule'];
    $row['details'] = ucwords(str_replace('_', ' ', (string) $row['status'])) . ' | ' . ($row['event_category'] ?: 'Reservation') . ($schedule ? ' | ' . $schedule : '');
    $row['url'] = page_url('reservation');
}
unset($row);
$addResults($results, 'Reservations', $reservationRows);

$visitorWhere = '(v.full_name LIKE :q OR v.visitor_type LIKE :q OR v.company LIKE :q OR v.person_to_visit LIKE :q OR v.purpose LIKE :q OR v.status LIKE :q OR v.scheduled_date LIKE :q OR CAST(v.id AS CHAR) LIKE :q)';
$visitorParams = ['q' => $like];
if (!t8_has_role(['admin', 'front_desk', 'facilities_staff'])) {
    $visitorWhere .= ' AND v.logged_by = :user_id';
    $visitorParams['user_id'] = t8_current_user_id();
}
$visitorRows = $search(
    "SELECT v.id, v.full_name AS title, CONCAT(COALESCE(v.visitor_type, 'Visitor'), ' | ', v.status, ' | VIS-', LPAD(v.id, 6, '0')) AS details
     FROM team8_visitors v WHERE {$visitorWhere} ORDER BY v.created_at DESC LIMIT 8",
    $visitorParams
);
foreach ($visitorRows as &$row) {
    $row['url'] = page_url('visitor');
}
unset($row);
$addResults($results, 'Visitors', $visitorRows);

$documentWhere = '(d.title LIKE :q OR d.document_type LIKE :q OR d.status LIKE :q OR d.expiration_date LIKE :q OR CAST(d.id AS CHAR) LIKE :q)';
$documentParams = ['q' => $like];
if (!t8_has_role('admin')) {
    $documentWhere .= ' AND d.uploaded_by = :user_id';
    $documentParams['user_id'] = t8_current_user_id();
}
$documentRows = $search(
    "SELECT d.id, d.title, CONCAT(COALESCE(d.document_type, 'Document'), ' | ', d.status) AS details
     FROM team8_documents d WHERE {$documentWhere} ORDER BY d.created_at DESC LIMIT 8",
    $documentParams
);
foreach ($documentRows as &$row) {
    $row['url'] = page_url('documents', ['action' => 'browse']);
}
unset($row);
$addResults($results, 'Documents', $documentRows);

if (t8_has_role(['admin', 'legal_officer', 'facilities_staff', 'employee'])) {
    $contractWhere = '(c.title LIKE :q OR c.status LIKE :q OR c.start_date LIKE :q OR c.end_date LIKE :q OR c.renewal_date LIKE :q OR CAST(c.id AS CHAR) LIKE :q OR p.name LIKE :q)';
    $contractParams = ['q' => $like];
    if (!t8_has_role('admin')) {
        $contractWhere .= ' AND c.owner_id = :user_id';
        $contractParams['user_id'] = t8_current_user_id();
    }
    $contractRows = $search(
        "SELECT DISTINCT c.id, c.title, CONCAT('Contract #', c.id, ' | ', c.status) AS details
         FROM team8_contracts c
         LEFT JOIN team8_contract_parties cp ON cp.contract_id = c.id
         LEFT JOIN team8_parties p ON p.id = cp.party_id
         WHERE c.deleted_at IS NULL AND {$contractWhere}
         ORDER BY c.created_at DESC LIMIT 8",
        $contractParams
    );
    foreach ($contractRows as &$row) {
        $row['url'] = page_url('contracts', t8_has_role('admin') ? ['action' => 'edit', 'id' => $row['id']] : []);
    }
    unset($row);
    $addResults($results, 'Contracts', $contractRows);
}

$retentionWhere = '(d.title LIKE :q OR s.record_type LIKE :q OR r.status LIKE :q OR r.disposition_date LIKE :q OR CAST(r.id AS CHAR) LIKE :q)';
$retentionParams = ['q' => $like];
if (!t8_has_role('admin')) {
    $retentionWhere .= ' AND r.custodian_id = :user_id';
    $retentionParams['user_id'] = t8_current_user_id();
}
$retentionRows = $search(
    "SELECT r.id, d.title, CONCAT('Record #', r.id, ' | ', s.record_type, ' | ', r.status) AS details
     FROM team8_records r JOIN team8_documents d ON d.id = r.document_id JOIN team8_retention_schedules s ON s.id = r.schedule_id
     WHERE {$retentionWhere} ORDER BY r.created_at DESC LIMIT 8",
    $retentionParams
);
foreach ($retentionRows as &$row) {
    $row['title'] = (string) $row['title'];
    $row['url'] = page_url('retention');
}
unset($row);
$addResults($results, 'Records Retention', $retentionRows);

if (t8_has_role(['admin', 'legal_officer'])) {
    $legalWhere = '(lc.title LIKE :q OR lc.subject LIKE :q OR lc.status LIKE :q OR lc.filed_date LIKE :q OR lc.deadline LIKE :q OR CAST(lc.id AS CHAR) LIKE :q)';
    $legalParams = ['q' => $like];
    if (!t8_has_role('admin')) {
        $legalWhere .= ' AND lc.assigned_to = :user_id';
        $legalParams['user_id'] = t8_current_user_id();
    }
    $legalRows = $search(
        "SELECT lc.id, lc.title, CONCAT('Case #', lc.id, ' | ', lc.status, ' | ', COALESCE(lc.subject, '')) AS details
         FROM team8_legal_cases lc WHERE lc.deleted_at IS NULL AND {$legalWhere}
         ORDER BY lc.created_at DESC LIMIT 8",
        $legalParams
    );
    foreach ($legalRows as &$row) {
        $row['url'] = page_url('legal', $isAdmin ? ['action' => 'edit', 'id' => $row['id']] : []);
    }
    unset($row);
    $addResults($results, 'Legal Cases', $legalRows);
}

if (t8_has_role('admin')) {
    $auditRows = $search(
        "SELECT id, CONCAT(action, ' | ', entity_type) AS title,
            CONCAT('Record #', entity_id, ' | ', created_at) AS details
         FROM audit_logs
         WHERE action LIKE :q OR entity_type LIKE :q OR old_value LIKE :q OR new_value LIKE :q OR CAST(entity_id AS CHAR) LIKE :q
         ORDER BY created_at DESC, id DESC LIMIT 8",
        ['q' => $like]
    );
    foreach ($auditRows as &$row) {
        $row['url'] = page_url('audit');
    }
    unset($row);
    $addResults($results, 'Audit Logs', $auditRows);

    $userRows = $search(
        "SELECT id, full_name AS title, email AS details
         FROM users WHERE full_name LIKE :q OR email LIKE :q OR CAST(id AS CHAR) LIKE :q
         ORDER BY full_name LIMIT 8",
        ['q' => $like]
    );
    foreach ($userRows as &$row) {
        $row['url'] = page_url('dashboard');
    }
    unset($row);
    $addResults($results, 'Users', $userRows);
}

$grouped = [];
foreach ($results as $result) {
    $grouped[$result['module']][] = $result;
}
echo json_encode(['results' => $grouped], JSON_UNESCAPED_SLASHES);
