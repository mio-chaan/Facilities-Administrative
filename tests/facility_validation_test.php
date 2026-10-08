<?php
declare(strict_types=1);

require_once __DIR__ . '/../modules/facilities/facility_helpers.php';

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
    $passed++;
};
$valid = static fn(string $type, string $capacity): array => t8_facility_validate(
    'Main Hall',
    'Building A',
    $type,
    $capacity,
    ['Building A']
);

$assert($valid('Room', '1') === [], 'Minimum room capacity is accepted.');
$assert($valid('Room', '100') === [], 'Maximum room capacity is accepted.');
$assert($valid('Area', '100') === [], 'Maximum area capacity is accepted.');
$assert($valid('Equipment', '200') === [], 'Maximum equipment quantity is accepted.');
$assert($valid('Asset', '200') === [], 'Maximum asset quantity is accepted.');
$assert($valid('Utility', '200') === [], 'Maximum utility quantity is accepted.');
$assert(in_array('Please select a valid facility type.', $valid('Unknown', '1'), true), 'Unknown facility types are rejected.');
$assert(count($valid('Room', '0')) > 0, 'Zero capacity is rejected.');
$assert(count($valid('Room', '-1')) > 0, 'Negative capacity is rejected.');
$assert(count($valid('Room', '1.5')) > 0, 'Fractional capacity is rejected.');
$assert(count($valid('Room', '1x')) > 0, 'Nonnumeric capacity is rejected.');
$assert(count($valid('Room', '101')) > 0, 'Capacity above the UI maximum is rejected server-side.');
$assert(count($valid('Equipment', '201')) > 0, 'Quantity above the UI maximum is rejected server-side.');
$assert(t8_facility_input_value(['name' => ['array']], 'name') === '', 'Array-valued POST input is safely rejected as empty.');
$assert(count(t8_facility_validate('', '', '', '', [])) >= 4, 'Missing required facility fields are rejected.');
$assert(
    in_array('Please select a location from the list, or add it first with "+ Add Location".', t8_facility_validate('Hall', 'Unknown', 'Room', '1', ['Building A']), true),
    'A location outside the configured list is rejected.'
);

echo "{$passed} facility validation tests passed." . PHP_EOL;
