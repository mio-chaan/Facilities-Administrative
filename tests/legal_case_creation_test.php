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
$formStart = strpos($source, '<?php elseif ($showForm): ?>');
$formEnd = strpos($source, '</form>', $formStart);
$assert($formStart !== false && $formEnd !== false, 'The case form should remain present.');
$caseForm = substr($source, $formStart, $formEnd - $formStart);
foreach (['case_number', 'supporting_staff_id', 'description', 'next_action_date', 'closing_date'] as $field) {
    $assert(str_contains($schema, $field), "Fresh-install schema is missing {$field}.");
    $assert(str_contains($migration, $field), "Upgrade migration is missing {$field}.");
}
$caseInformationMigration = file_get_contents(__DIR__ . '/../database/migrations/2026_09_26_legal_case_information.sql');
foreach (['court_agency', 'branch_office', 'docket_reference', 'jurisdiction', 'location', 'legal_basis', 'current_action'] as $field) {
    $assert(str_contains($schema, $field), "Fresh-install schema is missing {$field}.");
    $assert(str_contains($caseInformationMigration, $field), "Case information migration is missing {$field}.");
}
$assert(str_contains($source, 'mb_strlen($formValues[$field]) > $maxLength'), 'Optional case information should receive server-side length validation.');
$assert(str_contains($source, 'External / Legal Information'), 'The case workspace should display legal-specific case information.');
$assert(str_contains($schema, 'CREATE TABLE team8_legal_case_number_sequences'), 'Fresh installs should include yearly case number sequences.');
$assert(str_contains($migration, 'CREATE TABLE team8_legal_case_number_sequences'), 'The upgrade migration should create yearly case number sequences.');
$assert(str_contains($schema, 'case_number VARCHAR(20) NOT NULL UNIQUE'), 'Fresh-install case numbers should be unique and required.');
$assert(str_contains($migration, 'YEAR(created_at)'), 'Legacy numbering should use each case creation year.');
$assert(str_contains($helper, 'FOR UPDATE'), 'Case number allocation should lock the yearly sequence.');
$assert(str_contains($source, 't8_legal_next_case_number($pdo'), 'New cases should receive a generated number.');
$assert(str_contains($source, '$pdo->beginTransaction();'), 'Number allocation and case persistence should share a transaction.');
$assert(str_contains($caseForm, 'name="department_id"') && !str_contains($caseForm, 'name="department_id" required'), 'Department should be optional.');
$caseFormMarkup = preg_replace('/<\?.*?\?>/s', '', $caseForm);
$assert(is_string($caseFormMarkup), 'The case form markup should be readable.');
foreach (['title', 'case_type_id', 'priority', 'filed_date', 'assigned_to'] as $requiredField) {
    $assert(preg_match('/name="' . preg_quote($requiredField, '/') . '"[^>]*required/', $caseFormMarkup) === 1, "{$requiredField} should be required in the primary form.");
}
$assert(str_contains($source, "'status'      => (string) (\$_POST['status'] ?? (\$existing['status'] ?? 'under_review'))"), 'New cases should default to Under Review when no status is submitted.');
$assert(str_contains($caseForm, "<?php if (\$action === 'edit'): ?><div class=\"t8-field\">"), 'Status transitions should remain available only when editing an existing case.');
$assert(str_contains($source, "'status' => 'under_review'") && str_contains($source, "'priority' => 'medium'"), 'Status and Priority should default to Under Review and Medium on create.');
$assert(str_contains($caseForm, 'name="deadline"') && !preg_match('/name="deadline"[^>]*required/', $caseForm), 'Deadline should remain optional and visible.');
$assert(str_contains($caseForm, 'name="description"') && !preg_match('/name="description"[^>]*required/', $caseForm), 'Description should remain optional and visible.');
$assert(str_contains($caseForm, '<details') && str_contains($caseForm, 'Advanced / Court Details'), 'Optional details should be grouped in a collapsed Advanced / Court Details section.');
$assert(!str_contains($caseForm, 'name="subject"'), 'Subject must not be editable in the case form.');
$assert(!str_contains($caseForm, 'name="supporting_staff_id"'), 'Supporting Staff must not be editable in the case form.');
$assert(!str_contains($caseForm, 'name="next_action_date"'), 'Next Action Date must not be editable in the case form.');
$assert(!str_contains($caseForm, 'name="closing_date"'), 'Closing Date must not be editable in the case form.');
$assert(str_contains($source, '$legalHasCaseCreationFields || !$legalHasCaseMetadata || !$legalHasPriority'), 'The create form should require its Phase 2 and Phase 3 migrations.');
$assert(str_contains($source, '$legalHasCaseInformation'), 'Case information fields should be migration-gated.');
foreach (['court_agency', 'branch_office', 'docket_reference', 'jurisdiction', 'location', 'legal_basis', 'current_action'] as $field) {
    $assert(str_contains($caseForm, 'name="' . $field . '"'), "Advanced case details should include {$field}.");
}
$assert(str_contains($source, "'department_id' => \$departmentId"), 'An empty Department should be bound as NULL instead of integer zero.');
$assert(str_contains($source, "'description' => \$formValues['description'] !== '' ? \$formValues['description'] : null"), 'An empty Description should be persisted as NULL.');
$assert(str_contains($source, "'deadline' => \$formValues['deadline'] !== '' ? \$formValues['deadline'] : null"), 'An empty Deadline should be persisted as NULL.');
$assert(!str_contains($caseForm, 'name="case_number"'), 'Users must not submit or edit a generated case number.');

echo "Legal case creation checks passed.\n";