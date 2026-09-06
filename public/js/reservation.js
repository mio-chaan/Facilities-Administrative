/** Facility-type-driven reservation form behaviour. */
document.addEventListener('DOMContentLoaded', function () {
    var cancellationModal = document.getElementById('t8CancellationRequestModal');
    document.querySelectorAll('[data-cancel-reservation-id]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!cancellationModal) return;
            document.getElementById('t8CancellationReservationId').value = button.getAttribute('data-cancel-reservation-id');
            document.getElementById('t8CancellationReason').value = '';
            cancellationModal.showModal();
        });
    });
    document.querySelectorAll('[data-close-cancellation-modal]').forEach(function (button) {
        button.addEventListener('click', function () { cancellationModal.close(); });
    });
    if (cancellationModal) {
        cancellationModal.querySelector('form').addEventListener('submit', function (event) {
            var reason = document.getElementById('t8CancellationReason');
            var error = document.getElementById('t8CancellationReasonError');
            if (!reason.value.trim()) {
                event.preventDefault();
                error.hidden = false;
                reason.focus();
            } else {
                error.hidden = true;
            }
        });
    }
    var form = document.getElementById('t8ReservationForm');

    // ---- AJAX filtering for reservation tables (Facility/Type/Status,
    //      plus Search/Schedule on the "All Reservations" admin toolbar) ----
    document.querySelectorAll('[data-filter-table][data-filter-type]').forEach(function (filters) {
        var table = document.getElementById(filters.getAttribute('data-filter-table'));
        if (!table) return;

        var facilitySelect = filters.querySelector('[data-filter-facility]');
        var typeSelect = filters.querySelector('[data-filter-type-select]');
        var statusSelect = filters.querySelector('[data-filter-status]');
        var searchInput = filters.querySelector('[data-filter-search]');
        var rangeSelect = filters.querySelector('[data-filter-range]');
        var chipsEl = filters.querySelector('[data-filter-chips]');
        var resultCountEl = filters.querySelector('[data-filter-result-count]');
        var tableBody = table.querySelector('tbody');
        var filterType = filters.getAttribute('data-filter-type');
        var searchTimer = null;

        if (!tableBody) return;

        function buildParams() {
            var params = new URLSearchParams();
            params.append('page', 'reservation');
            params.append('ajax_filter', '1');
            params.append('table', filterType);
            if (facilitySelect && facilitySelect.value) params.append('facility', facilitySelect.value);
            if (typeSelect && typeSelect.value) params.append('type', typeSelect.value);
            if (statusSelect && statusSelect.value) params.append('status', statusSelect.value);
            if (searchInput && searchInput.value.trim()) params.append('search', searchInput.value.trim());
            if (rangeSelect && rangeSelect.value) params.append('range', rangeSelect.value);
            return params;
        }

        function renderChips() {
            if (!chipsEl) return;
            var chips = [];
            if (facilitySelect && facilitySelect.value) {
                chips.push({
                    label: 'Facility: ' + facilitySelect.options[facilitySelect.selectedIndex].text,
                    clear: function () { facilitySelect.value = ''; }
                });
            }
            if (typeSelect && typeSelect.value) {
                chips.push({
                    label: 'Type: ' + typeSelect.value,
                    clear: function () { typeSelect.value = ''; }
                });
            }
            if (statusSelect && statusSelect.value) {
                chips.push({
                    label: 'Status: ' + statusSelect.options[statusSelect.selectedIndex].text,
                    clear: function () { statusSelect.value = ''; }
                });
            }
            if (searchInput && searchInput.value.trim()) {
                chips.push({
                    label: 'Search: "' + searchInput.value.trim() + '"',
                    clear: function () { searchInput.value = ''; }
                });
            }
            if (rangeSelect && rangeSelect.value) {
                chips.push({
                    label: 'Schedule: ' + rangeSelect.options[rangeSelect.selectedIndex].text,
                    clear: function () { rangeSelect.value = ''; }
                });
            }

            chipsEl.innerHTML = '';
            if (!chips.length) {
                chipsEl.innerHTML = '<span class="t8-filter-empty-chips">No filters applied</span>';
                return;
            }
            chips.forEach(function (chip) {
                var el = document.createElement('span');
                el.className = 't8-filter-chip';
                el.textContent = chip.label + ' ';
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.innerHTML = '&times;';
                btn.setAttribute('aria-label', 'Remove filter');
                btn.addEventListener('click', function () {
                    chip.clear();
                    applyAjaxFilters();
                });
                el.appendChild(btn);
                chipsEl.appendChild(el);
            });
            var clearAll = document.createElement('button');
            clearAll.type = 'button';
            clearAll.className = 't8-filter-clear-all';
            clearAll.textContent = 'Clear all filters';
            clearAll.addEventListener('click', function () {
                if (facilitySelect) facilitySelect.value = '';
                if (typeSelect) typeSelect.value = '';
                if (statusSelect) statusSelect.value = '';
                if (searchInput) searchInput.value = '';
                if (rangeSelect) rangeSelect.value = '';
                applyAjaxFilters();
            });
            chipsEl.appendChild(clearAll);
        }

        function applyAjaxFilters() {
            var params = buildParams();
            fetch('?' + params.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (tableBody && typeof data.html !== 'undefined') {
                        closeOpenMenu();
                        tableBody.innerHTML = data.html;
                    }
                    if (resultCountEl && typeof data.total !== 'undefined' && typeof data.count !== 'undefined') {
                        resultCountEl.innerHTML = 'Showing <strong>' + data.count + '</strong> of <strong>' + data.total + '</strong> reservations';
                    }
                    renderChips();
                    initMeatballMenus(tableBody);
                })
                .catch(function (error) { console.error('Filter error:', error); });
        }

        if (facilitySelect) facilitySelect.addEventListener('change', applyAjaxFilters);
        if (typeSelect) typeSelect.addEventListener('change', applyAjaxFilters);
        if (statusSelect) statusSelect.addEventListener('change', applyAjaxFilters);
        if (rangeSelect) rangeSelect.addEventListener('change', applyAjaxFilters);
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(applyAjaxFilters, 300);
            });
        }

        // Chip "remove" buttons rendered server-side on first page load
        // (progressive enhancement / no-JS-safe) — wire them the same
        // way as the JS-rendered ones above so removing a chip doesn't
        // require a full reload once JS is available.
        if (chipsEl) {
            var controlsByKey = { facility: facilitySelect, type: typeSelect, status: statusSelect, search: searchInput, range: rangeSelect };
            chipsEl.querySelectorAll('[data-remove-filter]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var control = controlsByKey[btn.getAttribute('data-remove-filter')];
                    if (control) control.value = '';
                    applyAjaxFilters();
                });
            });
            var clearAllServer = chipsEl.querySelector('.t8-filter-clear-all');
            if (clearAllServer) {
                clearAllServer.addEventListener('click', function () {
                    Object.keys(controlsByKey).forEach(function (key) {
                        if (controlsByKey[key]) controlsByKey[key].value = '';
                    });
                    applyAjaxFilters();
                });
            }
        }
    });

    document.querySelectorAll('[data-reservation-filters]').forEach(function (filters) {
        var table = document.getElementById(filters.getAttribute('data-filter-table'));
        if (!table) return;
        var month = filters.querySelector('[data-filter-month]');
        var year = filters.querySelector('[data-filter-year]');
        if (!month || !year) return;
        var rows = Array.prototype.slice.call(table.querySelectorAll('[data-reservation-row]'));
        var years = {};
        rows.forEach(function (row) { var date = row.getAttribute('data-reservation-date') || ''; if (date) years[date.slice(0, 4)] = true; });
        Object.keys(years).sort().reverse().forEach(function (value) { var option = document.createElement('option'); option.value = value; option.textContent = value; year.appendChild(option); });
        function applyFilters() {
            rows.forEach(function (row) {
                var date = row.getAttribute('data-reservation-date') || '';
                var matchesMonth = !month.value || Number(date.slice(5, 7)) === Number(month.value);
                var matchesYear = !year.value || date.slice(0, 4) === year.value;
                row.hidden = !(matchesMonth && matchesYear);
            });
        }
        month.addEventListener('change', applyFilters);
        year.addEventListener('change', applyFilters);
    });

    // ===================================================================
    // Row meatball menu (.t8-res-menu-trigger / .t8-res-menu-panel) —
    // "All Reservations" (admin). PORTALS the panel to <body> on open,
    // same technique as public/js/visitor.js's scheduled-visits menu, so
    // it can never be clipped by .t8-table-wrap's overflow-x: auto.
    // ===================================================================
    var openPanel = null;
    var openTrigger = null;
    var placeholder = null;

    function closeOpenMenu() {
        if (!openPanel) return;
        openPanel.classList.remove('t8-portal-open', 't8-portal-visible');
        openPanel.style.top = '';
        openPanel.style.left = '';
        if (placeholder && placeholder.parentNode) {
            placeholder.parentNode.insertBefore(openPanel, placeholder);
            placeholder.parentNode.removeChild(placeholder);
        }
        if (openTrigger) {
            openTrigger.setAttribute('aria-expanded', 'false');
            openTrigger.classList.remove('is-active');
        }
        openPanel = null;
        openTrigger = null;
        placeholder = null;
    }

    function positionMenu(panel, trigger) {
        var margin = 8;
        var rect = trigger.getBoundingClientRect();
        var pw = panel.offsetWidth;
        var ph = panel.offsetHeight;
        var spaceBelow = window.innerHeight - rect.bottom;
        var openUpward = spaceBelow < (ph + margin) && rect.top > (ph + margin);
        var top = openUpward ? (rect.top - ph - margin) : (rect.bottom + margin);
        top = Math.max(margin, Math.min(top, window.innerHeight - ph - margin));
        var left = rect.right - pw;
        left = Math.max(margin, Math.min(left, window.innerWidth - pw - margin));
        panel.style.top = top + 'px';
        panel.style.left = left + 'px';
    }

    function openMenuFor(trigger) {
        var wrap = trigger.closest('.t8-res-menu');
        var panel = wrap ? wrap.querySelector('.t8-res-menu-panel') : null;
        if (!panel) return;

        if (openPanel === panel) {
            closeOpenMenu();
            return;
        }
        closeOpenMenu();

        placeholder = document.createComment('t8-res-menu-slot');
        panel.parentNode.insertBefore(placeholder, panel);
        document.body.appendChild(panel);

        panel.classList.add('t8-portal-open');
        positionMenu(panel, trigger);
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { panel.classList.add('t8-portal-visible'); });
        });

        openPanel = panel;
        openTrigger = trigger;
        trigger.setAttribute('aria-expanded', 'true');
        trigger.classList.add('is-active');
    }

    function initMeatballMenus(scope) {
        (scope || document).querySelectorAll('.t8-res-menu-trigger').forEach(function (trigger) {
            if (trigger.dataset.t8ResMenuBound === '1') return;
            trigger.dataset.t8ResMenuBound = '1';
            trigger.setAttribute('aria-haspopup', 'true');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.addEventListener('click', function (e) {
                e.stopPropagation();
                openMenuFor(trigger);
            });
        });
    }
    initMeatballMenus(document);

    document.addEventListener('click', function (e) {
        if (!openPanel) return;
        var clickedInsidePanel = openPanel.contains(e.target);
        var clickedTrigger = openTrigger && openTrigger.contains(e.target);
        if (!clickedInsidePanel && !clickedTrigger) closeOpenMenu();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeOpenMenu(); });
    window.addEventListener('resize', function () { if (openPanel && openTrigger) positionMenu(openPanel, openTrigger); });
    window.addEventListener('scroll', function () { if (openPanel && openTrigger) positionMenu(openPanel, openTrigger); }, true);

    // ---- "Copy Reservation Ref" ----
    document.addEventListener('click', function (e) {
        var copyBtn = e.target.closest('.t8-res-copy-ref');
        if (!copyBtn) return;
        var ref = copyBtn.getAttribute('data-copy') || '';
        if (navigator.clipboard && ref) {
            navigator.clipboard.writeText(ref).catch(function () {});
        }
        var original = copyBtn.innerHTML;
        copyBtn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
        setTimeout(function () { copyBtn.innerHTML = original; }, 1400);
        closeOpenMenu();
    });

    // ---- "View Details" -> populate the shared detail <dialog> from
    //      the meatball trigger's data-* attributes ----
    var detailModal = document.getElementById('t8ReservationDetailModal');
    var statusLabels = { approved: 'Approved', cancellation_pending: 'Pending', pending: 'Pending', cancelled: 'Cancelled', rejected: 'Rejected', completed: 'Completed', expired: 'Expired' };

    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    function getActiveMenuTriggerForItem(item) {
        if (!item) return null;
        if (openPanel && openPanel.contains(item) && openTrigger) {
            return openTrigger;
        }
        var wrap = item.closest('.t8-res-menu');
        if (wrap) {
            var trigger = wrap.querySelector('.t8-res-menu-trigger');
            if (trigger) return trigger;
        }
        return null;
    }

    document.addEventListener('click', function (e) {
        var viewBtn = e.target.closest('.t8-res-view-details');
        if (!viewBtn || !detailModal) return;
        var trigger = getActiveMenuTriggerForItem(viewBtn);
        if (!trigger) return;
        var d = trigger.dataset;

        setText('t8ResDetailTitle', (d.facility || 'Reservation') + (d.category ? ' — ' + d.category : ''));
        setText('t8ResDetailRef', d.ref || '');
        setText('t8ResDetailStatus', statusLabels[d.status] || (d.status || '—'));
        setText('t8ResDetailType', d.facilityType || '—');
        setText('t8ResDetailRequester', d.requester || '—');
        setText('t8ResDetailDepartment', d.department || '—');
        setText('t8ResDetailKeyPerson', d.keyPerson || '—');
        setText('t8ResDetailCategory', d.category || '—');
        setText('t8ResDetailSchedule', d.scheduleDetail || d.schedulePrimary || '—');
        setText('t8ResDetailLocation', d.facilityLocation || '—');
        setText('t8ResDetailCreated', d.created ? 'Created ' + d.created : '');
        setText('t8ResDetailUpdated', d.updated ? 'Last updated ' + d.updated : '');

        var qtyWrap = document.getElementById('t8ResDetailQtyWrap');
        if (d.quantity) {
            qtyWrap.hidden = false;
            setText('t8ResDetailQty', 'Qty ' + d.quantity + (d.returnDate ? ' · Return by ' + d.returnDate : ''));
        } else {
            qtyWrap.hidden = true;
        }

        var participantsWrap = document.getElementById('t8ResDetailParticipantsWrap');
        if (d.participants) {
            participantsWrap.hidden = false;
            setText('t8ResDetailParticipants', d.participants);
        } else {
            participantsWrap.hidden = true;
        }

        var requirementsWrap = document.getElementById('t8ResDetailRequirementsWrap');
        if (d.requirements) {
            requirementsWrap.hidden = false;
            setText('t8ResDetailRequirements', d.requirements);
        } else {
            requirementsWrap.hidden = true;
        }

        var notesEl = document.getElementById('t8ResDetailNotes');
        if (d.notes) {
            notesEl.textContent = d.notes;
            notesEl.classList.remove('is-empty');
        } else {
            notesEl.textContent = 'No additional notes were provided for this reservation.';
            notesEl.classList.add('is-empty');
        }

        var remarksWrap = document.getElementById('t8ResDetailRemarksWrap');
        if (d.remarks) {
            remarksWrap.hidden = false;
            setText('t8ResDetailRemarks', d.remarks);
        } else {
            remarksWrap.hidden = true;
        }

        document.getElementById('t8ResDetailConflict').hidden = d.conflict !== '1';

        closeOpenMenu();
        if (typeof detailModal.showModal === 'function') detailModal.showModal();
    });

    if (detailModal) {
        detailModal.querySelectorAll('[data-close-detail-modal]').forEach(function (btn) {
            btn.addEventListener('click', function () { detailModal.close(); });
        });
        detailModal.addEventListener('click', function (e) {
            if (e.target === detailModal) detailModal.close();
        });
    }

    if (!form) return;

    var facility = document.getElementById('facility_id');
    var category = document.getElementById('event_category');
    var config = JSON.parse(form.getAttribute('data-facility-config') || '{}');
    var fields = form.querySelectorAll('[data-reservation-field]');
    var availability = document.getElementById('t8ReservationAvailability');
    var suggestionsButton = document.getElementById('t8ReservationSuggestionsButton');
    var suggestions = document.getElementById('t8ReservationSuggestions');
    var availabilityTimer = null;

    function selectedType() {
        var option = facility.options[facility.selectedIndex];
        return option ? option.getAttribute('data-facility-type') || '' : '';
    }

    function supportsSuggestions() {
        var type = selectedType();
        return !!facility.value && ['Room', 'Area'].indexOf(type) !== -1;
    }

    function applyParticipantCapacity() {
        var participants = document.getElementById('expected_participants');
        var selectedOption = facility.options[facility.selectedIndex];
        var capacity = selectedOption ? Number(selectedOption.getAttribute('data-facility-capacity') || 0) : 0;
        if (!participants) return;

        participants.max = capacity > 0 ? String(capacity) : '';
        if (capacity > 0 && participants.value !== '' && Number(participants.value) > capacity) {
            participants.value = String(capacity);
        }
    }

    function resetTypeFields() {
        category.value = '';
        fields.forEach(function (wrapper) {
            var input = wrapper.querySelector('input, select, textarea');
            if (input) {
                input.value = '';
                if (window.T8Validate) T8Validate.clearError(input);
            }
        });
    }

    function applyType(reset) {
        var type = selectedType();
        var selectedOption = facility.options[facility.selectedIndex];
        var capacity = selectedOption ? Number(selectedOption.getAttribute('data-facility-capacity') || 0) : 0;
        var settings = config[type] || { event_categories: [], visible_fields: [], required_fields: [] };
        var selectedCategory = category.value;
        var participants = document.getElementById('expected_participants');
        var previousParticipants = participants ? participants.value : '';
        if (reset) resetTypeFields();
        if (reset && participants && settings.visible_fields.indexOf('participants') !== -1) {
            participants.value = previousParticipants;
        }

        if (suggestionsButton) {
            suggestionsButton.hidden = !supportsSuggestions();
        }
        if (suggestions) {
            suggestions.hidden = true;
        }

        category.innerHTML = '<option value="">Select a category…</option>';
        settings.event_categories.forEach(function (value) {
            var option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            option.selected = !reset && value === selectedCategory;
            category.appendChild(option);
        });
        category.disabled = !type;

        fields.forEach(function (wrapper) {
            var field = wrapper.getAttribute('data-reservation-field');
            var active = settings.visible_fields.indexOf(field) !== -1;
            var input = wrapper.querySelector('input, select, textarea');
            wrapper.hidden = !active;
            if (input) {
                input.disabled = !active;
                input.required = active && settings.required_fields.indexOf(field) !== -1;
                if (field === 'participants' || field === 'quantity') {
                    input.max = capacity > 0 ? String(field === 'quantity' ? Math.min(capacity, 3) : capacity) : '';
                }
                if (!active && window.T8Validate) T8Validate.clearError(input);
            }
        });
        applyParticipantCapacity();
        var returnDate = document.getElementById('return_date');
        if (returnDate && ['Equipment', 'Asset'].indexOf(type) !== -1 && !returnDate.value) {
            returnDate.value = new Date().toISOString().slice(0, 10);
        }
    }

    facility.addEventListener('change', function () { applyType(true); });
    var participants = document.getElementById('expected_participants');
    if (participants) participants.addEventListener('input', applyParticipantCapacity);
    applyType(false);

    function updateAvailability() {
        if (!facility.value || !supportsSuggestions()) {
            if (availability) availability.hidden = true;
            if (suggestionsButton) suggestionsButton.hidden = true;
            if (suggestions) suggestions.hidden = true;
            return;
        }
        if (!availability || !document.getElementById('start_time').value || !document.getElementById('end_time').value) {
            if (availability) availability.hidden = true;
            if (suggestionsButton) suggestionsButton.hidden = true;
            if (suggestions) suggestions.hidden = true;
            return;
        }
        clearTimeout(availabilityTimer);
        var selectedFacilityId = facility.value;
        var selectedFacilityType = selectedType();
        availabilityTimer = setTimeout(function () {
            var params = new URLSearchParams({
                facility_id: selectedFacilityId,
                start_time: document.getElementById('start_time').value,
                end_time: document.getElementById('end_time').value
            });
            var editId = new URLSearchParams(window.location.search).get('id');
            if (editId) params.set('exclude_id', editId);
            fetch('reservation_availability.php?' + params.toString(), { headers: { 'Accept': 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    // Ignore responses for a facility that is no longer selected.
                    // Otherwise a delayed Room/Area availability request could reveal
                    // the suggestions button after switching to another facility type.
                    if (facility.value !== selectedFacilityId || selectedType() !== selectedFacilityType || !supportsSuggestions()) {
                        return;
                    }
                    availability.textContent = data.message || 'Availability could not be checked.';
                    availability.style.color = data.available ? 'var(--t8-success)' : 'var(--t8-danger)';
                    availability.hidden = false;
                    if (suggestionsButton) suggestionsButton.hidden = !!data.available;
                })
                .catch(function () {
                    if (facility.value !== selectedFacilityId || selectedType() !== selectedFacilityType || !supportsSuggestions()) {
                        return;
                    }
                    availability.textContent = 'Availability could not be checked right now.';
                    availability.style.color = 'var(--t8-danger)';
                    availability.hidden = false;
                    if (suggestionsButton) suggestionsButton.hidden = true;
                });
        }, 180);
    }
    if (suggestionsButton) {
        suggestionsButton.addEventListener('click', function () {
            suggestionsButton.disabled = true;
            suggestionsButton.textContent = 'Finding alternatives...';
            var params = new URLSearchParams({
                facility_id: facility.value,
                start_time: document.getElementById('start_time').value,
                end_time: document.getElementById('end_time').value
            });
            fetch('reservation_suggestions.php?' + params.toString(), { headers: { 'Accept': 'application/json' } })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    suggestions.textContent = data.suggestions || 'No alternative dates found.';
                    suggestions.hidden = false;
                })
                .catch(function () {
                    suggestions.textContent = 'Alternative dates are unavailable right now.';
                    suggestions.hidden = false;
                })
                .finally(function () {
                    suggestionsButton.disabled = false;
                    suggestionsButton.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> Suggest alternatives';
                });
        });
    }
    ['change', 'input'].forEach(function (eventName) {
        [facility, document.getElementById('start_time'), document.getElementById('end_time')].forEach(function (element) {
            element.addEventListener(eventName, updateAvailability);
        });
    });
    updateAvailability();

    /**
     * FIX (show error messages when required fields are left empty):
     * every required, currently-visible field is checked here and, on
     * failure, gets an inline message right under the field (via
     * T8Validate, loaded before this file — see templates/footer.php)
     * in addition to the server-side re-check that always runs too.
     */
    function markField(fieldEl, isValid, message) {
        if (!fieldEl) {
            return isValid;
        }
        if (window.T8Validate) {
            if (isValid) {
                T8Validate.clearError(fieldEl);
            } else {
                T8Validate.showError(fieldEl, message);
            }
        }
        return isValid;
    }

    form.addEventListener('submit', function (event) {
        var type = selectedType();
        var settings = config[type] || { required_fields: [], event_categories: [] };
        var valid = true;

        if (!markField(facility, !!facility.value, 'Please select a facility.')) valid = false;
        if (!markField(category, !!category.value, 'Please select an event category.')) valid = false;

        var departmentEl = document.getElementById('department');
        if (!markField(departmentEl, !!departmentEl.value, 'Department is required.')) valid = false;

        var keyPersonEl = document.getElementById('key_person');
        if (!markField(keyPersonEl, !!keyPersonEl.value.trim(), 'Key person / point of contact is required.')) valid = false;

        var inputs = { participants: 'expected_participants', quantity: 'quantity', return_date: 'return_date', remarks: 'remarks', schedule: 'schedule', requirements: 'requirements' };
        Object.keys(inputs).forEach(function (field) {
            var el = document.getElementById(inputs[field]);
            if (!el) return;
            if (settings.required_fields.indexOf(field) !== -1) {
                if (!markField(el, !!el.value.trim(), 'This field is required.')) valid = false;
            } else if (window.T8Validate) {
                T8Validate.clearError(el);
            }
        });

        if (settings.required_fields.indexOf('time_range') !== -1) {
            var start = document.getElementById('start_time');
            var end = document.getElementById('end_time');
            if (!markField(start, !!start.value, 'Start time is required.')) valid = false;
            if (!markField(end, !!end.value, 'End time is required.')) valid = false;
            if (start.value && end.value && !markField(end, new Date(start.value) < new Date(end.value), 'End time must be after start time.')) {
                valid = false;
            }
            if (start.value && !markField(start, new Date(start.value) > new Date(), 'Please select a future schedule.')) valid = false;
        }

        if (settings.required_fields.indexOf('schedule') !== -1) {
            var schedule = document.getElementById('schedule');
            if (schedule.value && !markField(schedule, new Date(schedule.value) > new Date(), 'Please select a future schedule.')) valid = false;
        }

        if (!valid) {
            event.preventDefault();
        }
    });
});
