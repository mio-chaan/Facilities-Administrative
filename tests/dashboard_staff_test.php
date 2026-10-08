<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/includes/helpers.php';

function t8_current_user_id(): ?int
{
    return 7;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE team8_reservations (id INTEGER, user_id INTEGER, status TEXT, start_time TEXT, schedule TEXT, deleted_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE team8_visitors (logged_by INTEGER)');
$pdo->exec('CREATE TABLE team8_documents (uploaded_by INTEGER, deleted_at TEXT)');
$pdo->exec('CREATE TABLE team8_records (custodian_id INTEGER, deleted_at TEXT)');
$pdo->exec('CREATE TABLE team8_compliance_checks (checked_by INTEGER, result TEXT)');
$pdo->exec("INSERT INTO team8_reservations VALUES (101, 7, 'pending', NULL, '2026-10-10', NULL, '2026-10-01')");
$pdo->exec("INSERT INTO team8_reservations VALUES (102, 8, 'pending', NULL, '2026-10-10', NULL, '2026-10-01')");
$pdo->exec('INSERT INTO team8_visitors VALUES (7), (8)');
$pdo->exec('INSERT INTO team8_documents VALUES (7, NULL), (8, NULL)');
$pdo->exec('INSERT INTO team8_records VALUES (7, NULL), (8, NULL)');
$pdo->exec("INSERT INTO team8_compliance_checks VALUES (7, 'needs_review'), (8, 'non_compliant')");

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};
$render = static function () use ($pdo): string {
    ob_start();
    include __DIR__ . '/../modules/dashboard/staff.php';
    return (string) ob_get_clean();
};
$countFor = static function (string $html, string $label): ?string {
    $pattern = '/' . preg_quote($label, '/') . '<\/p>\s*<div class="t8-staff-stat-value">(\d+)<\/div>/';
    return preg_match($pattern, $html, $matches) === 1 ? $matches[1] : null;
};

$html = $render();
$assert(str_contains($html, 'My Dashboard'), 'Staff dashboard renders successfully.');
$assert($countFor($html, 'My Reservations') === '1', 'Reservation totals are limited to the signed-in user.');
$assert($countFor($html, 'My Visitors') === '1', 'Visitor totals are limited to the signed-in user.');
$assert($countFor($html, 'My Documents') === '1', 'Document totals are limited to the signed-in user.');
$assert($countFor($html, 'My Records') === '1', 'Record totals are limited to the signed-in user.');
$assert($countFor($html, 'My Tasks') === '1', 'Task totals are limited to the signed-in user.');
$assert(str_contains($html, 'Reservation #101') && !str_contains($html, 'Reservation #102'), 'Reservation updates do not expose another user.');
$assert(!str_contains($html, 'Some dashboard information could not be loaded.'), 'Successful data loading does not show an error.');

$pdo->exec('DROP TABLE team8_documents');
$errorHtml = $render();
$assert(str_contains($errorHtml, 'Some dashboard information could not be loaded.'), 'Database errors are visibly reported rather than shown as successful zero values.');

echo 'Dashboard staff tests passed.' . PHP_EOL;
