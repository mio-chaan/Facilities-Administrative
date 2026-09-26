<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/legal_case_helpers.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert(t8_legal_is_valid_iso_date('2024-02-29'), 'A valid leap-day date should be accepted.');
$assert(!t8_legal_is_valid_iso_date('2026-02-29'), 'An invalid leap-day date should be rejected.');
$assert(!t8_legal_is_valid_iso_date('2026-2-09'), 'Dates must use the strict ISO format.');
$assert(t8_legal_case_date_is_not_before_filed('2026-09-26', '2026-09-26'), 'Optional dates may equal the filed date.');
$assert(!t8_legal_case_date_is_not_before_filed('2026-09-26', '2026-09-25'), 'Optional dates may not precede the filed date.');
$assert(t8_legal_format_case_number(2026, 1) === 'LC-2026-001', 'The first case number should use a three-digit sequence.');
$assert(t8_legal_format_case_number(2026, 1000) === 'LC-2026-1000', 'Case number sequences should grow beyond three digits.');

$invalidSequenceRejected = false;
try {
    t8_legal_format_case_number(2026, 0);
} catch (InvalidArgumentException) {
    $invalidSequenceRejected = true;
}
$assert($invalidSequenceRejected, 'A non-positive case number sequence should be rejected.');

$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$migration = file_get_contents(__DIR__ . '/../database/migrations/2026_09_26_legal_case_creation.sql');
$helper = file_get_contents(__DIR__ . '/../app/includes/legal_case_helpers.php');
$source = file_get_contents(__DIR__ . '/../modules/legal/index.php');
foreach (['case_number', 'supporting_staff_id', 'description', 'next_action_date', 'closing_date'] as $field) {
    $assert(str_contains($schema, $field), "Fresh-install schema is missing {$field}.");
    $assert(str_contains($migration, $field), "Upgrade migration is missing {$field}.");
}
$assert(str_contains($schema, 'CREATE TABLE team8_legal_case_number_sequences'), 'Fresh installs should include yearly case number sequences.');
$assert(str_contains($migration, 'CREATE TABLE team8_legal_case_number_sequences'), 'The upgrade migration should create yearly case number sequences.');
$assert(str_contains($schema, 'case_number VARCHAR(20) NOT NULL UNIQUE'), 'Fresh-install case numbers should be unique and required.');
$assert(str_contains($migration, 'YEAR(created_at)'), 'Legacy numbering should use each case creation year.');
$assert(str_contains($helper, 'FOR UPDATE'), 'Case number allocation should lock the yearly sequence.');
$assert(str_contains($source, 't8_legal_next_case_number($pdo'), 'New cases should receive a generated number.');
$assert(str_contains($source, '$pdo->beginTransaction();'), 'Number allocation and case persistence should share a transaction.');
$assert(str_contains($source, 'name="department_id" required'), 'Department should be required by the create form.');
$assert(str_contains($source, 'name="assigned_to" required'), 'Assigned legal officer should be required by the create form.');
$assert(str_contains($source, 'name="status" required'), 'Case status should be required by the create form.');
$assert(str_contains($source, '$legalHasCaseCreationFields || !$legalHasCaseMetadata || !$legalHasPriority'), 'The create form should require its Phase 2 and Phase 3 migrations.');
$assert(str_contains($source, 'name="supporting_staff_id"'), 'Supporting staff should be optional on the create form.');
$assert(str_contains($source, 'name="next_action_date"'), 'Next action date should be captured.');
$assert(str_contains($source, 'name="closing_date"'), 'Closing date should be captured.');
$assert(!str_contains($source, 'name="case_number"'), 'Users must not submit or edit a generated case number.');

echo "Legal case creation checks passed.\n";