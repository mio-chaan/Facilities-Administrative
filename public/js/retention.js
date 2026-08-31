/**
 * retention.js
 * AJAX toggle for the retention archive/active view.
 */

document.addEventListener('DOMContentLoaded', function () {
    function bindRetentionToggle(toggleButton) {
        if (!toggleButton || toggleButton.dataset.t8RetentionBound === '1') {
            return;
        }

        toggleButton.dataset.t8RetentionBound = '1';
        toggleButton.addEventListener('click', function (event) {
            event.preventDefault();

            var url = new URL(toggleButton.getAttribute('href'), window.location.origin);
            var params = new URLSearchParams(url.search);
            params.set('page', 'retention');
            params.set('ajax_filter', '1');

            fetch('?' + params.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html'
                }
            })
                .then(function (response) { return response.text(); })
                .then(function (html) {
                    var wrapper = document.createElement('div');
                    wrapper.innerHTML = html;

                    var nextShell = wrapper.querySelector('#t8RetentionTableShell');
                    var shell = document.getElementById('t8RetentionTableShell');
                    var nextToggle = wrapper.querySelector('#t8RetentionToggle');
                    var currentToggle = document.getElementById('t8RetentionToggle');

                    if (nextShell && shell) {
                        shell.innerHTML = nextShell.innerHTML;
                    }

                    if (nextToggle && currentToggle) {
                        currentToggle.setAttribute('href', nextToggle.getAttribute('href'));
                        currentToggle.innerHTML = nextToggle.innerHTML;
                        currentToggle.dataset.t8RetentionBound = '0';
                        bindRetentionToggle(currentToggle);
                    }
                })
                .catch(function (error) {
                    console.error('Retention toggle error:', error);
                });
        });
    }

    bindRetentionToggle(document.getElementById('t8RetentionToggle'));
});
