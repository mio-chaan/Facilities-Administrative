document.addEventListener('DOMContentLoaded', function () {
    function initMeatballMenus(scope) {
        (scope || document).querySelectorAll('.t8-meatball-btn').forEach(function (button) {
            if (button.dataset.t8MeatballBound === '1') return;
            button.dataset.t8MeatballBound = '1';
            button.addEventListener('click', function (event) {
                event.stopPropagation();
                var menu = button.parentElement.querySelector('.t8-meatball-menu');
                var shouldOpen = menu && menu.hidden;
                closeMeatballMenus();
                if (shouldOpen) {
                    menu.hidden = false;
                    button.setAttribute('aria-expanded', 'true');
                }
            });
        });
    }

    function closeMeatballMenus() {
        document.querySelectorAll('.t8-meatball-menu:not([hidden])').forEach(function (menu) {
            menu.hidden = true;
            var button = menu.parentElement.querySelector('.t8-meatball-btn');
            if (button) button.setAttribute('aria-expanded', 'false');
        });
    }

    document.addEventListener('click', function (event) {
        if (!event.target.closest('.t8-meatball-wrap')) closeMeatballMenus();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeMeatballMenus();
    });

    initMeatballMenus(document);

    var form = document.getElementById('t8ContractsFilterForm');
    if (form) {

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
                initMeatballMenus(results);
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
    }

    document.querySelectorAll('.t8-tab[data-tab]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            var key = tab.getAttribute('data-tab');
            document.querySelectorAll('.t8-tab[data-tab]').forEach(function (item) {
                item.classList.toggle('is-active', item === tab);
                item.setAttribute('aria-selected', item === tab ? 'true' : 'false');
            });
            document.querySelectorAll('.t8-tab-panel').forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-panel') !== key;
            });
        });
    });

    var startDate = document.getElementById('start_date');
    var endDate = document.getElementById('end_date');
    var renewalDate = document.getElementById('renewal_date');
    if (startDate && endDate && renewalDate) {
        function updateDateLimits() {
            var startValue = startDate.value;
            var endValue = endDate.value;
            var today = new Date().toISOString().slice(0, 10);
            startDate.min = today;
            if (startValue) {
                endDate.min = startValue;
                renewalDate.min = startValue;
            } else {
                endDate.removeAttribute('min');
                renewalDate.min = today;
            }
            if (endValue) {
                renewalDate.max = endValue;
            } else {
                renewalDate.removeAttribute('max');
            }
            if (endValue && startValue && endValue < startValue) endDate.value = startValue;
            if (renewalDate.value && startValue && renewalDate.value < startValue) renewalDate.value = startValue;
            if (renewalDate.value && endValue && renewalDate.value > endValue) renewalDate.value = endValue;
        }
        startDate.addEventListener('change', updateDateLimits);
        endDate.addEventListener('change', updateDateLimits);
        updateDateLimits();
    }

    var modal = document.getElementById('t8PartyModal');
    if (modal) {
        var openModal = function () {
            modal.hidden = false;
            var firstInput = modal.querySelector('input:not([type="hidden"]),button');
            if (firstInput) firstInput.focus();
        };
        var closeModal = function () { modal.hidden = true; };
        document.querySelectorAll('[data-close-party-modal]').forEach(function (button) {
            button.addEventListener('click', closeModal);
        });
        (window.T8_PARTY_MODAL_OPEN_TRIGGERS || []).concat(['t8OpenPartyModal']).forEach(function (id) {
            var trigger = document.getElementById(id);
            if (trigger) trigger.addEventListener('click', openModal);
        });
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) closeModal();
        });

        var phoneInput = document.getElementById('party_phone');
        if (phoneInput) {
            phoneInput.addEventListener('input', function () {
                this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10);
            });
        }
        var tinInput = document.getElementById('tin');
        if (tinInput) {
            tinInput.addEventListener('input', function () {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        }
        var modalForm = document.getElementById('t8PartyModalForm');
        if (modalForm) {
            modalForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (phoneInput && phoneInput.value.length !== 10) {
                    alert('Phone number must be exactly 10 digits.');
                    return;
                }
                var url = new URL(modalForm.action, window.location.href);
                url.searchParams.set('ajax_create_party', '1');
                fetch(url.toString(), {
                    method: 'POST',
                    body: new FormData(modalForm),
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (!data.success) {
                            alert('Error: ' + data.error);
                            return;
                        }
                        closeModal();
                        modalForm.reset();
                        var selectedBox = document.getElementById('t8PartySelected');
                        if (!selectedBox || selectedBox.querySelector('[data-party-id="' + data.id + '"]')) return;
                        var chip = document.createElement('div');
                        chip.className = 't8-party-chip';
                        chip.setAttribute('data-party-id', String(data.id));
                        var label = document.createElement('span');
                        label.textContent = data.name;
                        var remove = document.createElement('button');
                        remove.type = 'button';
                        remove.className = 't8-party-remove';
                        remove.setAttribute('aria-label', 'Remove ' + data.name);
                        remove.textContent = '\u00d7';
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'party_ids[]';
                        input.value = data.id;
                        chip.append(label, remove, input);
                        selectedBox.appendChild(chip);
                    })
                    .catch(function () { alert('An unexpected error occurred.'); });
            });
        }
    }

    var picker = document.getElementById('t8PartyPicker');
    if (picker) {
        var searchInput = document.getElementById('t8PartySearch');
        var resultBox = document.getElementById('t8PartyResults');
        var selectedBox = document.getElementById('t8PartySelected');
        var partyTimer = null;
        var partyRequestId = 0;

        function renderPartyResults(parties, query) {
            resultBox.replaceChildren();
            resultBox.classList.toggle('has-results', parties.length > 0);
            parties.forEach(function (party) {
                var option = document.createElement('button');
                option.type = 'button';
                option.textContent = party.name + ' (' + party.type + ')';
                option.addEventListener('click', function () {
                    addParty(party.id, party.name);
                    searchInput.value = '';
                    resultBox.hidden = true;
                });
                resultBox.appendChild(option);
            });
            if (parties.length === 0) {
                var emptyState = document.createElement('div');
                emptyState.className = 't8-party-empty';
                var message = document.createElement('p');
                message.textContent = query ? 'No existing parties match "' + query + '".' : 'No existing parties yet.';
                var createButton = document.createElement('button');
                createButton.type = 'button';
                createButton.className = 't8-link-btn';
                createButton.setAttribute('data-open-new-party', '');
                createButton.innerHTML = '<i class="fa-solid fa-plus" aria-hidden="true"></i> Create a new party';
                emptyState.append(message, createButton);
                resultBox.appendChild(emptyState);
            }
        }

        function searchParties(query) {
            var currentRequest = ++partyRequestId;
            resultBox.hidden = false;
            resultBox.replaceChildren();
            resultBox.classList.remove('has-results');
            resultBox.classList.add('is-loading');
            fetch('index.php?page=party_registry&ajax_search=1&q=' + encodeURIComponent(query), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) { if (!response.ok) throw new Error('Party search failed.'); return response.json(); })
                .then(function (parties) {
                    if (currentRequest !== partyRequestId) return;
                    resultBox.classList.remove('is-loading');
                    renderPartyResults(parties, query);
                })
                .catch(function () {
                    if (currentRequest === partyRequestId) {
                        resultBox.classList.remove('is-loading');
                        resultBox.hidden = true;
                    }
                });
        }

        function addParty(id, name) {
            if (selectedBox.querySelector('[data-party-id="' + id + '"]')) return;
            var chip = document.createElement('div');
            chip.className = 't8-party-chip';
            chip.setAttribute('data-party-id', String(id));
            var label = document.createElement('span');
            label.textContent = name;
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 't8-party-remove';
            remove.setAttribute('aria-label', 'Remove ' + name);
            remove.textContent = '\u00d7';
            remove.addEventListener('click', function () { chip.remove(); });
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'party_ids[]';
            input.value = id;
            chip.append(label, remove, input);
            selectedBox.appendChild(chip);
        }

        selectedBox.addEventListener('click', function (event) {
            if (event.target.classList.contains('t8-party-remove')) event.target.closest('.t8-party-chip').remove();
        });
        searchInput.addEventListener('input', function () {
            window.clearTimeout(partyTimer);
            var query = searchInput.value.trim();
            if (query.length === 1) {
                partyRequestId++;
                resultBox.replaceChildren();
                resultBox.classList.remove('has-results', 'is-loading');
                resultBox.hidden = true;
                return;
            }
            partyTimer = window.setTimeout(function () { searchParties(query); }, query ? 250 : 0);
        });
        searchInput.addEventListener('focus', function () {
            if (searchInput.value.trim() === '') {
                window.clearTimeout(partyTimer);
                searchParties('');
            }
        });
        resultBox.addEventListener('click', function (event) {
            if (event.target.closest('[data-open-new-party]')) {
                var opener = document.getElementById('t8OpenPartyModal');
                if (opener) opener.click();
            }
        });
        document.addEventListener('click', function (event) {
            if (!event.target.closest('#t8PartyPicker')) resultBox.hidden = true;
        });
    }
});
