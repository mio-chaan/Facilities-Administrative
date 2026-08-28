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

    // ---------------------------------------------------------------
    // ARCHIVE FILTERS: Month/Year (existing) combined with the new
    // Type / Facility / Status filters. All active filters are ANDed
    // together against the row's matching data-reservation-* attribute.
    // ---------------------------------------------------------------
    document.querySelectorAll('[data-reservation-filters]').forEach(function (filters) {
        var table = document.getElementById(filters.getAttribute('data-filter-table'));
        if (!table) return;

        var month = filters.querySelector('[data-filter-month]');
        var year = filters.querySelector('[data-filter-year]');
        var type = filters.querySelector('[data-filter-type]');
        var facility = filters.querySelector('[data-filter-facility]');
        var status = filters.querySelector('[data-filter-status]');

        var rows = Array.prototype.slice.call(table.querySelectorAll('[data-reservation-row]'));

        if (year) {
            var years = {};
            rows.forEach(function (row) {
                var date = row.getAttribute('data-reservation-date') || '';
                if (date) years[date.slice(0, 4)] = true;
            });
            Object.keys(years).sort().reverse().forEach(function (value) {
                var option = document.createElement('option');
                option.value = value;
                option.textContent = value;
                year.appendChild(option);
            });
        }

        function applyFilters() {
            rows.forEach(function (row) {
                var date = row.getAttribute('data-reservation-date') || '';
                var matchesMonth = !month || !month.value || Number(date.slice(5, 7)) === Number(month.value);
                var matchesYear = !year || !year.value || date.slice(0, 4) === year.value;
                var matchesType = !type || !type.value || row.getAttribute('data-reservation-type') === type.value;
                var matchesFacility = !facility || !facility.value || row.getAttribute('data-reservation-facility') === facility.value;
                var matchesStatus = !status || !status.value || row.getAttribute('data-reservation-status') === status.value;
                row.hidden = !(matchesMonth && matchesYear && matchesType && matchesFacility && matchesStatus);
            });
        }

        [month, year, type, facility, status].forEach(function (select) {
            if (select) select.addEventListener('change', applyFilters);
        });
    });

    if (!form) return;

    var facilitySelect = document.getElementById('facility_id');
    var category = document.getElementById('event_category');
    var config = JSON.parse(form.getAttribute('data-facility-config') || '{}');
    var fields = form.querySelectorAll('[data-reservation-field]');
    var quantityInput = document.getElementById('quantity');
    var participantsInput = document.getElementById('expected_participants');
    var quantityHint = document.getElementById('t8QuantityAvailabilityHint');
    var participantsHint = document.getElementById('t8ParticipantsCapacityHint');
    var availabilityUrl = form.getAttribute('data-availability-url') || '';
    var currentReservationId = form.getAttribute('data-reservation-id') || '';

    function selectedType() {
        var option = facilitySelect.options[facilitySelect.selectedIndex];
        return option ? option.getAttribute('data-facility-type') || '' : '';
    }

    function selectedCapacity() {
        var option = facilitySelect.options[facilitySelect.selectedIndex];
        var raw = option ? option.getAttribute('data-capacity') : null;
        return raw ? parseInt(raw, 10) : null;
    }

    // ---------------------------------------------------------------
    // CAPACITY VALIDATION: whenever the selected facility changes,
    // cap Participants at the facility's capacity, and (for
    // Equipment/Asset) fetch the CURRENT available quantity from
    // public/facility_availability.php and cap Quantity at that. The
    // server still re-validates on submit (see
    // t8_reservation_validate() / t8_reservation_committed_quantity()
    // in modules/reservation/index.php) - this is a convenience layer,
    // not the actual protection.
    // ---------------------------------------------------------------
    function refreshCapacityLimits() {
        var capacity = selectedCapacity();
        var type = selectedType();

        if (participantsInput) {
            if (capacity !== null) {
                participantsInput.max = String(capacity);
                if (participantsHint) {
                    participantsHint.hidden = false;
                    participantsHint.textContent = "Cannot exceed this facility's capacity (" + capacity + ").";
                }
            } else {
                participantsInput.removeAttribute('max');
                if (participantsHint) participantsHint.hidden = true;
            }
        }

        if (!quantityInput) return;

        var facilityId = facilitySelect.value;
        var isQuantityType = type === 'Equipment' || type === 'Asset';

        if (!isQuantityType || !facilityId || !availabilityUrl) {
            quantityInput.removeAttribute('max');
            if (quantityHint) quantityHint.hidden = true;
            return;
        }

        var url = availabilityUrl + '?facility_id=' + encodeURIComponent(facilityId);
        if (currentReservationId) {
            url += '&exclude_id=' + encodeURIComponent(currentReservationId);
        }

        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || typeof data.available !== 'number') return;
                quantityInput.max = String(data.available);
                if (quantityHint) {
                    quantityHint.hidden = false;
                    quantityHint.textContent = data.available + ' of ' + data.capacity + ' currently available.';
                }
            })
            .catch(function () {
                // Fail quietly - the server-side check still protects the data
                // even if this convenience lookup can't be reached.
            });
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
        var settings = config[type] || { event_categories: [], visible_fields: [], required_fields: [] };
        var selectedCategory = category.value;
        if (reset) resetTypeFields();

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
                if (!active && window.T8Validate) T8Validate.clearError(input);
            }
        });

        refreshCapacityLimits();
    }

    facilitySelect.addEventListener('change', function () { applyType(true); });
    applyType(false);

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

        if (!markField(facilitySelect, !!facilitySelect.value, 'Please select a facility.')) valid = false;
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

        // CAPACITY VALIDATION: block submit client-side when Quantity or
        // Participants exceeds the max="" set by refreshCapacityLimits().
        // This is convenience only — t8_reservation_validate() on the
        // server is the real enforcement and cannot be bypassed.
        if (quantityInput && quantityInput.max !== '' && quantityInput.value !== '') {
            var qtyValid = Number(quantityInput.value) <= Number(quantityInput.max);
            if (!markField(quantityInput, qtyValid, 'Only ' + quantityInput.max + ' unit(s) are currently available for this facility.')) valid = false;
        }
        if (participantsInput && participantsInput.max !== '' && participantsInput.value !== '') {
            var partValid = Number(participantsInput.value) <= Number(participantsInput.max);
            if (!markField(participantsInput, partValid, 'Cannot exceed this facility\'s capacity (' + participantsInput.max + ').')) valid = false;
        }

        if (settings.required_fields.indexOf('time_range') !== -1) {
            var start = document.getElementById('start_time');
            var end = document.getElementById('end_time');
            if (!markField(start, !!start.value, 'Start time is required.')) valid = false;
            if (!markField(end, !!end.value, 'End time is required.')) valid = false;
            if (start.value && end.value && !markField(end, new Date(start.value) < new Date(end.value), 'End time must be after start time.')) {
                valid = false;
            }
        }

        if (!valid) {
            event.preventDefault();
        }
    });
});

/**
 * DYNAMIC STATUS: polls public/reservation_status_poll.php every few
 * seconds for every reservation row currently on screen inside a
 * `.t8-live-status` table (Admin "All Reservations", Staff "My
 * Reservations", Staff "All Reservations") and patches the status
 * badge, conflict indicator, and cancel-eligibility note in place -
 * so an Approved -> Ongoing transition, or one admin/staff member's
 * action, shows up for everyone else looking at the same reservation
 * without a manual page refresh.
 *
 * Deliberately independent of the DOMContentLoaded block above so a
 * page with no live tables (e.g. the create/edit form) simply does
 * nothing here.
 */
document.addEventListener('DOMContentLoaded', function () {
    var liveTables = document.querySelectorAll('.t8-live-status');
    if (!liveTables.length) return;

    var POLL_INTERVAL_MS = 20000;
    var csrfInput = document.querySelector('input[name="csrf_token"]');
    var csrfToken = csrfInput ? csrfInput.value : '';

    var badgeLabels = {
        pending: 'Pending',
        approved: 'Approved',
        ongoing: 'Ongoing',
        completed: 'Completed',
        rejected: 'Rejected',
        cancelled: 'Cancelled',
        cancellation_pending: 'Cancellation Pending',
    };

    function statusLabel(status) {
        if (badgeLabels[status]) return badgeLabels[status];
        return status.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }

    function collectVisibleIds() {
        var ids = [];
        liveTables.forEach(function (table) {
            table.querySelectorAll('tr[data-reservation-id]').forEach(function (row) {
                var id = row.getAttribute('data-reservation-id');
                if (id) ids.push(id);
            });
        });
        return ids;
    }

    function applyUpdate(item) {
        var badge = document.getElementById('t8-res-status-' + item.id);
        if (badge) {
            // Swap the t8-badge-<oldStatus> class for the new one without
            // disturbing the plain "t8-badge" base class.
            Array.prototype.slice.call(badge.classList).forEach(function (cls) {
                if (cls.indexOf('t8-badge-') === 0) badge.classList.remove(cls);
            });
            badge.classList.add('t8-badge-' + item.display_status);
            badge.textContent = statusLabel(item.display_status);
        }

        var conflictCell = document.getElementById('t8-res-conflict-' + item.id);
        if (conflictCell) {
            if (item.has_conflict) {
                conflictCell.innerHTML = '<span class="t8-badge" title="Time Conflict" style="background:#E67E22; color:#fff; font-weight:700;"><i class="fa-solid fa-triangle-exclamation"></i> !</span>';
            } else {
                conflictCell.innerHTML = '<span class="t8-help-text">—</span>';
            }
        }

        // Once a reservation flips to Ongoing (or moves away from an
        // Approved state entirely), any "Cancel" / "Request
        // Cancellation" trigger button for it is no longer valid -
        // disable it in place rather than leaving a stale, clickable
        // button whose eventual POST would just be rejected server-side.
        var cancelButton = document.querySelector('[data-cancel-reservation-id="' + item.id + '"]');
        if (cancelButton && (item.display_status === 'ongoing' || item.status !== 'approved')) {
            cancelButton.disabled = true;
            cancelButton.title = 'This reservation is Ongoing or no longer Approved — contact an administrator.';
        }
    }

    function poll() {
        var ids = collectVisibleIds();
        if (!ids.length) return;

        var body = new URLSearchParams();
        ids.forEach(function (id) { body.append('ids[]', id); });
        body.append('csrf_token', csrfToken);

        fetch('reservation_status_poll.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !Array.isArray(data.reservations)) return;
                data.reservations.forEach(applyUpdate);
            })
            .catch(function () {
                // Silent - a failed poll just means badges stay as they were
                // until the next successful poll or a manual refresh.
            });
    }

    setInterval(poll, POLL_INTERVAL_MS);
});
