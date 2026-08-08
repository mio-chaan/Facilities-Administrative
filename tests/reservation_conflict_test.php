<?php
// Test harness for reservation conflict annotation
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE team8_reservations (
 id INTEGER PRIMARY KEY,
 facility_id INTEGER,
 user_id INTEGER,
 start_time TEXT,
 end_time TEXT,
 status TEXT
);
CREATE TABLE team8_facilities (
 id INTEGER PRIMARY KEY,
 name TEXT,
 facility_type TEXT,
 location TEXT,
 capacity INTEGER,
 status TEXT
);
CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT);");

$pdo->exec("INSERT INTO team8_facilities (id,name,facility_type,location,capacity,status) VALUES (1,'Ram-Yum','Area','Main',100,'active')");
$pdo->exec("INSERT INTO users (id,full_name) VALUES (1,'Dev Tester')");

$now = time();
function fmt($t) { return date('Y-m-d H:i:s', $t); }

// Existing approved booking (ongoing)
$stmt = $pdo->prepare('INSERT INTO team8_reservations (id,facility_id,user_id,start_time,end_time,status) VALUES (?,?,?,?,?,?)');
$stmt->execute([1,1,1, fmt($now - 60), fmt($now + 60), 'approved']);

// Reservation under test (ongoing) - should show conflict
$stmt->execute([2,1,1, fmt($now - 30), fmt($now + 30), 'approved']);

// Future overlapping reservation - should NOT show conflict now
$stmt->execute([3,1,1, fmt($now + 3600), fmt($now + 3660), 'approved']);

// Past overlapping reservation - should NOT show conflict now
$stmt->execute([4,1,1, fmt($now - 7200), fmt($now - 7100), 'approved']);

// Minimal helper stubs required by the module
function t8_current_user_id() { return 1; }
function t8_has_role($roles) { return true; }
function t8_csrf_verify($t = null) { return true; }
function t8_audit_log() { }
function t8_flash_set() { }
function redirect($url) { /* no-op to avoid exiting */ }
function page_url($p, $q = []) { return 'test'; }
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function t8_csrf_field() { return ''; }
function format_date($str, $fmt) { $ts = strtotime($str); return $ts ? date($fmt, $ts) : $str; }

// Make $pdo available to the included module
$GLOBALS['pdo'] = $pdo;

// Include the reservation module in a buffer to avoid HTML output mixing
ob_start();
require __DIR__ . '/../modules/reservation/index.php';
ob_end_clean();

// After inclusion, the module should have populated $allReservations (admin path).
if (!isset($allReservations)) {
    echo "ERROR: \$allReservations not set.\n";
    exit(1);
}

$output = [];
foreach ($allReservations as $r) {
    $output[] = [
        'id' => $r['id'],
        'start_time' => $r['start_time'],
        'end_time' => $r['end_time'],
        'status' => $r['status'],
        'has_conflict' => $r['has_conflict'] ? true : false,
    ];
}

echo json_encode($output, JSON_PRETTY_PRINT) . "\n";

return 0;
