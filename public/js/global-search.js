document.addEventListener('DOMContentLoaded', function () {
    var search = document.getElementById('t8GlobalSearch');
    var panel = document.getElementById('t8GlobalSearchResults');
    if (!search || !panel) return;

    var timer = null;
    var request = null;

    function closeResults() {
        panel.hidden = true;
        panel.innerHTML = '';
    }

    function renderResults(groups) {
        panel.innerHTML = '';
        var modules = Object.keys(groups || {});
        if (!modules.length) {
            panel.innerHTML = '<div class="t8-global-search-empty">No results found.</div>';
            panel.hidden = false;
            return;
        }
        modules.forEach(function (module) {
            var section = document.createElement('section');
            section.className = 't8-global-search-group';
            var heading = document.createElement('h3');
            heading.textContent = module;
            section.appendChild(heading);
            groups[module].forEach(function (result) {
                var link = document.createElement('a');
                link.className = 't8-global-search-result';
                link.href = result.url;
                var title = document.createElement('strong');
                title.textContent = result.title;
                link.appendChild(title);
                if (result.details) {
                    var details = document.createElement('span');
                    details.textContent = result.details;
                    link.appendChild(details);
                }
                section.appendChild(link);
            });
            panel.appendChild(section);
        });
        panel.hidden = false;
    }

    function searchGlobal() {
        var value = search.value.trim();
        if (!value) {
            closeResults();
            return;
        }
        if (request) request.abort();
        request = fetch(search.getAttribute('data-search-url') + '?q=' + encodeURIComponent(value), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) { return response.ok ? response.json() : { results: {} }; })
            .then(function (data) {
                if (search.value.trim() === value) renderResults(data.results);
            })
            .catch(function (error) {
                if (error.name !== 'AbortError') renderResults({});
            });
    }

    search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(searchGlobal, 220);
    });
    search.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            clearTimeout(timer);
            searchGlobal();
        }
        if (event.key === 'Escape') closeResults();
    });
    document.addEventListener('click', function (event) {
        if (!search.closest('.t8-navbar-search').contains(event.target)) closeResults();
    });
});
