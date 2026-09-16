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

    // ---- NTE picker: AJAX search, filters, pagination, and page size ----
    var nteFilterForm = document.getElementById('t8NteFilterForm');
    var nteResults = document.getElementById('t8NteResults');

    if (nteFilterForm && nteResults) {
        var nteSearchInput = nteFilterForm.querySelector('input[name="nte_search"]');
        var nteTypeInput = nteFilterForm.querySelector('select[name="nte_type"]');
        var nteDateInput = nteFilterForm.querySelector('select[name="nte_date"]');
        var nteResetButton = document.getElementById('t8NteReset');
        var nteSearchTimer = null;

        function buildNteUrl() {
            var formAction = nteFilterForm.getAttribute('action') || window.location.href;
            var url = new URL(formAction, window.location.origin);
            var formData = new FormData(nteFilterForm);
            formData.forEach(function (value, key) {
                if (value !== '') {
                    url.searchParams.set(key, value);
                } else {
                    url.searchParams.delete(key);
                }
            });
            url.searchParams.set('nte_page', '1');
            return url;
        }

        function bindNteResults() {
            nteResults.querySelectorAll('[data-nte-page-link]').forEach(function (link) {
                link.addEventListener('click', function (event) {
                    event.preventDefault();
                    applyAjaxFilters(new URL(link.href, window.location.origin));
                });
            });

            var pageSizeInput = nteResults.querySelector('[data-nte-page-size]');
            if (pageSizeInput) {
                pageSizeInput.addEventListener('change', function () {
                    var url = buildNteUrl();
                    url.searchParams.set('nte_per_page', pageSizeInput.value);
                    applyAjaxFilters(url);
                });
            }
        }

        function applyAjaxFilters(url) {
            url = url || buildNteUrl();
            url.searchParams.set('page', 'documents');
            url.searchParams.set('action', 'nte_new');
            fetch(url.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html'
                }
            })
                .then(function (response) { return response.text(); })
                .then(function (html) {
                    var container = document.createElement('div');
                    container.innerHTML = html;
                    var nextResults = container.querySelector('#t8NteResults');
                    if (!nextResults) {
                        return;
                    }
                    nteResults.innerHTML = nextResults.innerHTML;
                    window.history.replaceState({}, '', url.toString());
                    bindNteResults();
                })
                .catch(function (error) {
                    if (error.name !== 'AbortError') {
                        console.error('NTE filter error:', error);
                    }
                });
        }

        if (nteSearchInput) {
            nteSearchInput.addEventListener('input', function () {
                window.clearTimeout(nteSearchTimer);
                nteSearchTimer = window.setTimeout(function () {
                    applyAjaxFilters();
                }, 300);
            });
        }
        if (nteTypeInput) {
            nteTypeInput.addEventListener('change', function () { applyAjaxFilters(); });
        }
        if (nteDateInput) {
            nteDateInput.addEventListener('change', function () { applyAjaxFilters(); });
        }
        if (nteResetButton) {
            nteResetButton.addEventListener('click', function () {
                if (nteSearchInput) nteSearchInput.value = '';
                if (nteTypeInput) nteTypeInput.value = '';
                if (nteDateInput) nteDateInput.value = '';
                applyAjaxFilters();
            });
        }
        nteFilterForm.addEventListener('submit', function (event) {
            event.preventDefault();
            applyAjaxFilters();
        });
        bindNteResults();
    }

    // ---- Browse list: AJAX filter without a full page reload ----
    var filterForm = document.getElementById('t8DocumentsFilterForm');
    var resultsContainer = document.getElementById('t8DocumentsResults');

    if (!filterForm || !resultsContainer) {
        initRecipientPickers();
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

    // ---- Memorandum and certificate recipient picker ----
    function initRecipientPickers() {
        document.querySelectorAll('[data-recipient-picker]').forEach(function (picker) {
        var trigger = picker.querySelector('[data-recipient-trigger]');
        var panel = picker.querySelector('[data-recipient-panel]');
        var search = picker.querySelector('[data-recipient-search]');
        var checkboxes = Array.prototype.slice.call(picker.querySelectorAll('[data-recipient-checkbox]'));
        var allDepartments = picker.querySelector('[value="all_departments"]');
        var summary = picker.querySelector('[data-recipient-summary]');
        var emptyLabel = picker.getAttribute('data-empty-label') || 'Select departments';

        if (!trigger || !panel || !summary) return;

        function selectedLabels() {
            return checkboxes.filter(function (checkbox) { return checkbox.checked; }).map(function (checkbox) {
                return checkbox.parentElement.querySelector('span').textContent.trim();
            });
        }

        function syncPicker() {
            var selected = selectedLabels();
            summary.textContent = selected.length ? selected.join(', ') : emptyLabel;
            if (allDepartments && allDepartments.checked) {
                checkboxes.forEach(function (checkbox) {
                    if (checkbox !== allDepartments) checkbox.checked = false;
                });
            }
        }

        trigger.addEventListener('click', function () {
            var isOpen = !panel.hidden;
            panel.hidden = isOpen;
            trigger.setAttribute('aria-expanded', String(!isOpen));
            if (!isOpen && search) search.focus();
        });
        checkboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                if (checkbox === allDepartments && checkbox.checked) {
                    checkboxes.forEach(function (other) { if (other !== checkbox) other.checked = false; });
                } else if (checkbox !== allDepartments && checkbox.checked && allDepartments) {
                    allDepartments.checked = false;
                }
                syncPicker();
            });
        });
        if (search) {
            search.addEventListener('input', function () {
                var query = search.value.trim().toLowerCase();
                picker.querySelectorAll('[data-recipient-option]').forEach(function (option) {
                    option.hidden = query !== '' && option.textContent.toLowerCase().indexOf(query) === -1;
                });
            });
        }
        document.addEventListener('click', function (event) {
            if (!picker.contains(event.target)) {
                panel.hidden = true;
                trigger.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                panel.hidden = true;
                trigger.setAttribute('aria-expanded', 'false');
            }
        });
        syncPicker();
        });
    }
    initRecipientPickers();
});
