<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/includes/legal_case_helpers.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert(
    T8_LEGAL_CASE_STATUSES === ['open', 'under_review', 'active', 'resolved', 'closed', 'archived'],
    'The Legal status vocabulary should match the approved lifecycle.'
);
$assert(t8_legal_case_type_code_from_name('Corporate / Business') === 'corporate_business', 'Case type keys should be stable normalized slugs.');
$assert(t8_legal_case_type_can_deactivate('labor'), 'Ordinary case types should be deactivatable.');
$assert(!t8_legal_case_type_can_deactivate('other'), 'Other must not be deactivatable.');
$assert(t8_legal_case_type_is_selectable(['is_active' => 1]), 'Active case types should be selectable.');
$assert(!t8_legal_case_type_is_selectable(['is_active' => 0]), 'Inactive case types should not be selectable.');
$assert(t8_legal_is_valid_iso_date('2026-09-26'), 'Valid ISO case dates should be accepted.');
$assert(!t8_legal_is_valid_iso_date('2026-02-30'), 'Impossible calendar dates should be rejected.');
$assert(!t8_legal_is_valid_iso_date('09/26/2026'), 'Non-ISO case dates should be rejected.');
$assert(t8_legal_case_date_is_not_before_filed('2026-09-26', '2026-09-26'), 'A case date may match the filed date.');
$assert(!t8_legal_case_date_is_not_before_filed('2026-09-26', '2026-09-25'), 'A case date may not precede the filed date.');
$assert(t8_legal_format_case_number(2026, 1) === 'LC-2026-001', 'Case numbers should use the planned year and padded sequence format.');

$typeCodes = [
    'labor',
    'employee_legal_matter',
    'civil',
    'administrative',
    'corporate_business',
    'government_regulatory',
    'compliance',
    'contract_dispute',
    'other',
];
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$migration = file_get_contents(__DIR__ . '/../database/migrations/2026_09_26_legal_case_catalogs.sql');
foreach ($typeCodes as $typeCode) {
    $assert(str_contains($schema, "('{$typeCode}',"), "Fresh-install schema is missing case type {$typeCode}.");
    $assert(str_contains($migration, "('{$typeCode}',"), "Upgrade migration is missing case type {$typeCode}.");
}
$assert(str_contains($migration, "archived_from_status = status, status = 'archived'"), 'Upgrade migration should preserve each archived case status for restore.');
$assert(str_contains($migration, 'SET case_type_id = @legal_other_type_id'), 'Existing cases should backfill to Other before case type is required.');

echo "Legal catalog and status checks passed.\n";