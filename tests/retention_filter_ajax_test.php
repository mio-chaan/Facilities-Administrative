<?php
$source = file_get_contents(__DIR__ . '/../modules/retention/index.php');

$checks = [
    'toggle shell present' => str_contains($source, 'id="t8RetentionTableShell"'),
    'toggle button present' => str_contains($source, 'id="t8RetentionToggle"'),
    'status toggle is not plain href' => !str_contains($source, 'id="t8RetentionToggle" href='),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        echo "FAIL: {$label}\n";
        exit(1);
    }
}

echo "PASS: retention AJAX toggle contract is in place\n";
