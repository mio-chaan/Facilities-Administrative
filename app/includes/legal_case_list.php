<?php
declare(strict_types=1);

function t8_legal_page_number($value): int
{
    $page = is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1 ? (int) $value : 1;
    return max(1, min($page, 100));
}

function t8_legal_page_url(int $page, array $filters): string
{
    return page_url('legal', array_filter(array_merge($filters, ['legal_page' => $page]), static fn ($value): bool => $value !== '' && $value !== null));
}

function t8_legal_pagination(int $page, int $totalPages, array $filters): void
{
    if ($totalPages < 2) {
        return;
    }
    echo '<nav class="t8-pagination" aria-label="Legal case pages">';
    if ($page > 1) {
        echo '<a class="t8-btn t8-btn-outline t8-btn-sm" href="' . e(t8_legal_page_url($page - 1, $filters)) . '">Previous</a>';
    }
    echo '<span class="t8-help-text">Page ' . e((string) $page) . ' of ' . e((string) $totalPages) . '</span>';
    if ($page < $totalPages) {
        echo '<a class="t8-btn t8-btn-outline t8-btn-sm" href="' . e(t8_legal_page_url($page + 1, $filters)) . '">Next</a>';
    }
    echo '</nav>';
}
