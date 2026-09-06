<?php
$source = file_get_contents(__DIR__ . '/../modules/documents/index.php');

$checks = [
    'form id present' => str_contains($source, 'id="t8DocumentsFilterForm"'),
    'results container present' => str_contains($source, 'id="t8DocumentsResults"'),
    'filter button removed' => !str_contains($source, '>Filter</button>'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        echo "FAIL: {$label}\n";
        exit(1);
    }
}

echo "PASS: documents AJAX filter contract is in place\n";
