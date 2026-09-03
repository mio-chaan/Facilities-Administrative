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
    
    // AJAX filtering for reservation tables
    document.querySelectorAll('[data-filter-table][data-filter-type]').forEach(function (filters) {
        var table = document.getElementById(filters.getAttribute('data-filter-table'));
        if (!table) return;
        
        var facilitySelect = filters.querySelector('[data-filter-facility]');
        var typeSelect = filters.querySelector('[data-filter-type-select]');
        var statusSelect = filters.querySelector('[data-filter-status]');
        var tableBody = table.querySelector('tbody');
        var filterType = filters.getAttribute('data-filter-type');
        
        if (!tableBody) return;
        
        function applyAjaxFilters() {
            var params = new URLSearchParams();
            params.append('page', 'reservation');
            params.append('ajax_filter', '1');
            params.append('table', filterType);
            if (facilitySelect && facilitySelect.value) params.append('facility', facilitySelect.value);
            if (typeSelect && typeSelect.value) params.append('type', typeSelect.value);
            if (statusSelect && statusSelect.value) params.append('status', statusSelect.value);
            
            fetch('?' + params.toString())
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (tableBody && data.html) {
                        tableBody.innerHTML = data.html;
                    }
                })
                .catch(function (error) { console.error('Filter error:', error); });
        }
        
        if (facilitySelect) facilitySelect.addEventListener('change', applyAjaxFilters);
        if (typeSelect) typeSelect.addEventListener('change', applyAjaxFilters);
        if (statusSelect) statusSelect.addEventListener('change', applyAjaxFilters);
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
        if (reset) resetTypeFields();

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
        var returnDate = document.getElementById('return_date');
        if (returnDate && ['Equipment', 'Asset'].indexOf(type) !== -1 && !returnDate.value) {
            returnDate.value = new Date().toISOString().slice(0, 10);
        }
    }

    facility.addEventListener('change', function () { applyType(true); });
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
