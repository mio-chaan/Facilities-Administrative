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

    // ---- Browse list: AJAX filter without a full page reload ----
    var filterForm = document.getElementById('t8DocumentsFilterForm');
    var resultsContainer = document.getElementById('t8DocumentsResults');

    if (!filterForm || !resultsContainer) {
        return;
    }

    var qInput = filterForm.querySelector('input[name="q"]');
    var categoryInput = filterForm.querySelector('select[name="category_id"]');
    var reviewInput = filterForm.querySelector('select[name="review_status"]');
    var pendingRequest = null;

    function buildDocumentsUrl() {
        var params = new URLSearchParams();
        params.set('page', 'documents');
        params.set('action', 'browse');
        params.set('q', qInput ? qInput.value.trim() : '');
        if (categoryInput && categoryInput.value) {
            params.set('category_id', categoryInput.value);
        }
        if (reviewInput && reviewInput.value) {
            params.set('review_status', reviewInput.value);
        }
        return '?' + params.toString();
    }

    function applyDocumentsFilter() {
        if (pendingRequest) {
            window.clearTimeout(pendingRequest);
        }

        pendingRequest = window.setTimeout(function () {
            var url = buildDocumentsUrl();
            window.history.replaceState({}, '', url);

            fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html'
                }
            })
                .then(function (response) { return response.text(); })
                .then(function (html) {
                    var container = document.createElement('div');
                    container.innerHTML = html;
                    var nextResults = container.querySelector('#t8DocumentsResults');
                    if (!nextResults) {
                        return;
                    }
                    resultsContainer.innerHTML = nextResults.innerHTML;
                })
                .catch(function (error) {
                    console.error('Documents filter error:', error);
                });
        }, 150);
    }

    if (qInput) {
        qInput.addEventListener('input', applyDocumentsFilter);
    }
    if (categoryInput) {
        categoryInput.addEventListener('change', applyDocumentsFilter);
    }
    if (reviewInput) {
        reviewInput.addEventListener('change', applyDocumentsFilter);
    }

    filterForm.addEventListener('submit', function (event) {
        event.preventDefault();
        applyDocumentsFilter();
    });
});
