<?php

declare(strict_types=1);

function page_url(string $page, array $query = []): string
{
    return '/index.php?' . http_build_query(array_merge(['page' => $page], $query));
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/../app/includes/legal_case_list.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert(t8_legal_page_number(null) === 1, 'Missing page should normalize to page 1.');
$assert(t8_legal_page_number('0') === 1, 'Zero page should normalize to page 1.');
$assert(t8_legal_page_number('3') === 3, 'Valid page should be preserved.');
$assert(t8_legal_page_number('999') === 100, 'Page should be capped at 100.');
$assert(t8_legal_page_number('1 OR 1=1') === 1, 'Invalid page input should normalize safely.');

$url = t8_legal_page_url(2, ['search' => 'employee complaint', 'status' => 'open']);
$assert(str_contains($url, 'page=legal'), 'Pagination URL should preserve the legal route.');
$assert(str_contains($url, 'search=employee+complaint'), 'Pagination URL should preserve search.');
$assert(str_contains($url, 'status=open'), 'Pagination URL should preserve status.');
$assert(str_contains($url, 'legal_page=2'), 'Pagination URL should set the requested legal page.');

ob_start();
t8_legal_pagination(1, 2, ['search' => 'employee']);
$pagination = (string) ob_get_clean();
$assert(str_contains($pagination, 'search=employee'), 'Rendered pagination should preserve active filters.');
$assert(str_contains($pagination, 'Page 1 of 2'), 'Rendered pagination should show the current page.');

$source = file_get_contents(__DIR__ . '/../modules/legal/index.php');
foreach (['case_type_id', 'department_id', 'priority_filter', 'filter_assigned_to', 'filed_date', 'deadline', 'LIMIT ', ' OFFSET '] as $required) {
    $assert(str_contains($source, $required), "Legal list implementation is missing {$required}.");
}
$assert(str_contains($source, '<th>Case No.</th>'), 'Legal cases should display their generated case number.');
$casesTableStart = strpos($source, 'class="t8-table t8-legal-cases-table"');
$casesTableEnd = $casesTableStart === false ? false : strpos($source, '</table>', $casesTableStart);
$casesTable = $casesTableStart === false || $casesTableEnd === false
    ? ''
    : substr($source, $casesTableStart, $casesTableEnd - $casesTableStart);
$assert($casesTable !== '', 'Legal cases table markup should be present.');
$assert(!str_contains($casesTable, '<th>Subject</th>'), 'Legal cases should not display the obsolete Subject column.');
$assert(!str_contains($casesTable, '<th>Department</th>'), 'Legal cases should not display the obsolete Department column.');
$assert(str_contains($source, 'lc.subject LIKE :search_subject'), 'Subject should remain searchable for legacy cases.');
$assert(str_contains($source, "\$c['case_number']"), 'The list and detail menu should use the persisted case number.');
$assert(str_contains($source, 'if (!$isAdmin)'), 'Legal list must retain non-admin authorization scoping.');

$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$priorityMigration = file_get_contents(__DIR__ . '/../database/migrations/2026_09_26_legal_case_priority.sql');
$assert(str_contains($schema, "priority    VARCHAR(20) NOT NULL DEFAULT 'medium'"), 'Fresh-install schema should define legal case priority.');
$assert(str_contains($priorityMigration, 'ADD COLUMN priority'), 'Upgrade migration should add legal case priority.');
$assert(str_contains($priorityMigration, "SET priority = 'medium'"), 'Priority migration should normalize legacy records.');
$assert(str_contains($source, 'name="priority"'), 'Create/edit form should expose priority.');
$assert(str_contains($source, '<th>Priority</th>'), 'Legal list should display priority.');
$assert(str_contains($source, '$pageSize = 5;'), 'Legal case pagination should show five cases per page.');

echo "Legal list checks passed.\n";
