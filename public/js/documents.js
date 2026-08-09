/**
 * documents.js
 * Small, dependency-free behaviors for the Document Management
 * dashboard and HR document template picker. Loaded conditionally by
 * templates/footer.php when $page === 'documents'.
 */

document.addEventListener('DOMContentLoaded', function () {
    // ---- Template picker: Generate HR Document (hr/generate.php) ----
    var continueBtn = document.getElementById('t8TemplateContinue');
    var templateOptions = document.querySelectorAll('.t8-template-option');

    if (continueBtn && templateOptions.length) {
        templateOptions.forEach(function (option) {
            var input = option.querySelector('input[type="radio"]');
            option.addEventListener('click', function () {
                input.checked = true;
                continueBtn.disabled = false;
            });
        });

        continueBtn.addEventListener('click', function () {
            var checked = document.querySelector('.t8-template-option input[type="radio"]:checked');
            if (!checked) { return; }
            var target = checked.closest('.t8-template-option').getAttribute('data-target');
            if (target) {
                window.location.href = target;
            }
        });
    }

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
