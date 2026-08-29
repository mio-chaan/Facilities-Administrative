/**
 * documents.js
 * Small, dependency-free behaviors for the Document Management
 * dashboard and HR document template picker. Loaded conditionally by
 * templates/footer.php when $page === 'documents'.
 */

document.addEventListener('DOMContentLoaded', function () {
    // ---- Dashboard: client-side filter of the Recent Documents list ----
    var searchInput = document.getElementById('t8DocsSearch');
    var recentItems = document.querySelectorAll('.t8-docs-recent-item');

    if (searchInput && recentItems.length) {
        searchInput.addEventListener('input', function () {
            var query = searchInput.value.trim().toLowerCase();
            recentItems.forEach(function (item) {
                var haystack = item.getAttribute('data-search') || '';
                var matches = query === '' || haystack.indexOf(query) !== -1;
                item.classList.toggle('t8-docs-hidden', !matches);
            });
        });
    }
});
