document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('t8ContractsFilterForm');
    if (!form) return;

    var table = document.getElementById(form.getAttribute('data-contract-filter-table'));
    var results = table ? table.querySelector('[data-contract-results]') : null;
    var search = form.querySelector('[data-contract-search]');
    var status = form.querySelector('[data-contract-status]');
    var type = form.querySelector('[data-contract-type]');
    var timer = null;
    var requestId = 0;

    function buildUrl() {
        var url = new URL(window.location.href);
        var formData = new FormData(form);
        url.searchParams.set('page', 'contracts');
        url.searchParams.set('ajax_filter', '1');
        ['search', 'status', 'contract_type'].forEach(function (name) {
            var value = String(formData.get(name) || '').trim();
            if (value) {
                url.searchParams.set(name, value);
            } else {
                url.searchParams.delete(name);
            }
        });
        var archived = form.querySelector('input[name="archived"]');
        if (archived) url.searchParams.set('archived', archived.value);
        return url;
    }

    function applyFilters() {
        if (!results) return;
        var url = buildUrl();
        var currentRequest = ++requestId;
        results.setAttribute('aria-busy', 'true');
        fetch(url.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                if (!response.ok) throw new Error('Contract filter request failed.');
                return response.json();
            })
            .then(function (data) {
                if (currentRequest !== requestId || typeof data.html === 'undefined') return;
                results.innerHTML = data.html;
                results.removeAttribute('aria-busy');
                if (window.T8RowMenu) window.T8RowMenu.init(results);
                url.searchParams.delete('ajax_filter');
                window.history.replaceState({}, '', url.toString());
            })
            .catch(function (error) {
                if (currentRequest === requestId) {
                    results.removeAttribute('aria-busy');
                    console.error('Contract filter error:', error);
                }
            });
    }

    if (search) {
        search.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(applyFilters, 300);
        });
    }
    if (status) status.addEventListener('change', applyFilters);
    if (type) {
        type.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(applyFilters, 300);
        });
    }
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        applyFilters();
    });
});
