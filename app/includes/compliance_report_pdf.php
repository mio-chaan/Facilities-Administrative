<?php
declare(strict_types=1);

function t8_compliance_report_pdf_html(array $report, array $findings, array $checks): string
{
    $department = (string) ($report['department_name'] ?? 'All Departments');
    $range = format_date((string) $report['date_from'], 'M d, Y') . ' - ' . format_date((string) $report['date_to'], 'M d, Y');
    $findingHtml = '';
    foreach ($findings as $finding) {
        $findingHtml .= '<li>' . nl2br(e((string) $finding)) . '</li>';
    }
    $checkRows = '';
    foreach ($checks as $check) {
        $checkRows .= '<tr><td>' . e(format_date((string) $check['check_date'], 'M d, Y')) . '</td><td>'
            . e((string) $check['record_title']) . '</td><td>' . e((string) ($check['department_name'] ?? 'Unassigned'))
            . '</td><td>' . e(ucwords(str_replace('_', ' ', (string) $check['result']))) . '</td><td>'
            . e((string) ($check['notes'] ?? '')) . '</td></tr>';
    }
    return '<!doctype html><html><head><meta charset="utf-8"><style>
        body{font-family:DejaVu Sans,sans-serif;color:#292321;font-size:10px}h1{color:#781d32;font-size:22px;margin:0 0 8px}h2{font-size:14px;color:#781d32;margin:22px 0 8px}
        .meta{color:#655b58;margin:0 0 18px}.summary{background:#f7f2f1;padding:14px;border-left:4px solid #a12b45;line-height:1.6}
        li{margin:0 0 6px;line-height:1.5}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ded8d6;padding:6px;text-align:left;vertical-align:top}th{background:#f4eded;color:#4d202b}
        </style></head><body><h1>' . e((string) $report['report_type']) . '</h1><p class="meta">' . e($department) . ' | ' . e($range) . '</p>
        <h2>Executive Summary</h2><div class="summary">' . nl2br(e((string) $report['executive_summary'])) . '</div>
        <h2>Key Findings</h2><ul>' . $findingHtml . '</ul><h2>Compliance Check Details</h2>
        <table><thead><tr><th>Date</th><th>Record</th><th>Department</th><th>Result</th><th>Notes</th></tr></thead><tbody>'
        . ($checkRows !== '' ? $checkRows : '<tr><td colspan="5">No checks were recorded.</td></tr>')
        . '</tbody></table></body></html>';
}