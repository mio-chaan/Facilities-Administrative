<?php
declare(strict_types=1);

function t8_dashboard_trend_year_options(int $selectedYear, int $currentYear): array
{
    $years = range($currentYear - 1, $currentYear + 2);
    if ($selectedYear >= 2000 && $selectedYear <= 2100 && !in_array($selectedYear, $years, true)) {
        $years[] = $selectedYear;
        sort($years);
    }
    return $years;
}
