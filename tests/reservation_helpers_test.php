<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/reservation_helpers.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'));
$pdo->exec(
    'CREATE TABLE team8_reservations (
        id INTEGER PRIMARY KEY,
        facility_id INTEGER,
        status TEXT,
        start_time TEXT,
        end_time TEXT,
        quantity INTEGER,
        expected_return_date TEXT
    )'
);
$pdo->exec("INSERT INTO team8_reservations VALUES
    (1, 5, 'approved', '2035-10-01 10:00:00', '2035-10-01 11:00:00', 2, '2035-10-05'),
    (2, 5, 'pending', '2035-10-01 11:00:00', '2035-10-01 12:00:00', 3, NULL),
    (3, 5, 'approved', NULL, NULL, 3, NULL),
    (4, 5, 'rejected', NULL, NULL, 10, NULL),
    (5, 5, 'approved', NULL, NULL, 5, '2000-01-01')");

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
    $passed++;
};

$assert(t8_normalize_datetime(' 2035-10-01T09:30 ') === '2035-10-01 09:30:00', 'Local datetime input is normalized.');
$assert(t8_normalize_datetime('2035-10-01 09:30:45') === '2035-10-01 09:30:45', 'Normalized datetime remains unchanged.');
$assert(t8_normalize_datetime('') === '', 'Empty datetime remains empty.');
$assert(t8_reservation_has_conflict($pdo, 5, '2035-10-01 10:30:00', '2035-10-01 11:30:00'), 'Overlapping approved reservation is detected.');
$assert(!t8_reservation_has_conflict($pdo, 5, '2035-10-01 11:00:00', '2035-10-01 12:00:00'), 'Adjacent reservation windows do not overlap.');
$assert(!t8_reservation_has_conflict($pdo, 5, '2035-10-01 10:30:00', '2035-10-01 11:30:00', 1), 'Editing excludes the current reservation from conflict checks.');
$assert(t8_reservation_committed_quantity($pdo, 5) === 8, 'Committed quantity counts pending/approved unreturned items only.');
$assert(t8_reservation_committed_quantity($pdo, 5, 1) === 6, 'Committed quantity excludes the current reservation when editing.');
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
$missingStartHasStarted = t8_reservation_has_started(['schedule' => null]);
restore_error_handler();
$assert(!$missingStartHasStarted && $warnings === [], 'Missing start time is handled without warnings.');
$assert(t8_reservation_display_status(['status' => 'pending']) === 'pending', 'Non-approved status remains unchanged without timestamps.');
$assert(
    t8_reservation_display_status(['status' => 'approved', 'start_time' => date('Y-m-d H:i:s', time() + 60)]) === 'approved',
    'Future approved reservation is not marked ongoing.'
);
$assert(
    t8_reservation_display_status([
        'status' => 'approved',
        'start_time' => date('Y-m-d H:i:s', time() - 60),
        'end_time' => date('Y-m-d H:i:s', time() + 60),
    ]) === 'ongoing',
    'Started approved reservation is shown as ongoing.'
);
$assert(
    t8_reservation_display_status([
        'status' => 'approved',
        'start_time' => date('Y-m-d H:i:s', time() - 120),
        'end_time' => date('Y-m-d H:i:s', time() - 60),
    ]) === 'completed',
    'Ended approved reservation is shown as completed.'
);

echo "{$passed} reservation helper tests passed." . PHP_EOL;
