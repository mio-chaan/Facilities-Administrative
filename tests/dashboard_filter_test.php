<?php
declare(strict_types=1);

require_once __DIR__ . '/../modules/dashboard/dashboard_helpers.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$standardYears = t8_dashboard_trend_year_options(2026, 2026);
$assert($standardYears === [2025, 2026, 2027, 2028], 'The default year selector range should remain unchanged.');
$assert(in_array(2000, t8_dashboard_trend_year_options(2000, 2026), true), 'A valid lower-bound year must be selectable.');
$assert(in_array(2100, t8_dashboard_trend_year_options(2100, 2026), true), 'A valid upper-bound year must be selectable.');
$assert(!in_array(1999, t8_dashboard_trend_year_options(1999, 2026), true), 'An unsupported year must not be added to the selector.');

echo 'Dashboard filter tests passed.' . PHP_EOL;
