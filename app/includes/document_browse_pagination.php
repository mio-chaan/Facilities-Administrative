<?php
/**
 * Shared bounds for the unified document browse pagination window.
 */
declare(strict_types=1);

const T8_DOCUMENT_BROWSE_PAGE_SIZE = 25;
const T8_DOCUMENT_BROWSE_MAX_PAGE = 100;

/** Normalize a user-supplied browse page without allowing huge offsets. */
function t8_document_browse_page_number($value): int
{
    if (is_int($value)) {
        $page = $value;
    } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
        $page = (int) $value;
    } else {
        $page = 1;
    }

    return max(1, min($page, T8_DOCUMENT_BROWSE_MAX_PAGE));
}

/** Return the bounded offset and source fetch limit for a browse page. */
function t8_document_browse_page_window(int $page, int $pageSize = T8_DOCUMENT_BROWSE_PAGE_SIZE): array
{
    $page = t8_document_browse_page_number($page);
    $pageSize = max(1, min($pageSize, 100));
    $offset = ($page - 1) * $pageSize;

    return [
        'page' => $page,
        'page_size' => $pageSize,
        'offset' => $offset,
        'source_limit' => $offset + $pageSize + 1,
    ];
}
