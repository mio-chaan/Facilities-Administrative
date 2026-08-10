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
    document.querySelectorAll('[data-reservation-filters]').forEach(function (filters) {
        var table = document.getElementById(filters.getAttribute('data-filter-table'));
        if (!table) return;
        var month = filters.querySelector('[data-filter-month]');
        var year = filters.querySelector('[data-filter-year]');
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

    function selectedType() {
        var option = facility.options[facility.selectedIndex];
        return option ? option.getAttribute('data-facility-type') || '' : '';
    }

    function resetTypeFields() {
        category.value = '';
        fields.forEach(function (wrapper) {
            var input = wrapper.querySelector('input, select, textarea');
            if (input) input.value = '';
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
            }
        });
    }

    facility.addEventListener('change', function () { applyType(true); });
    applyType(false);

    form.addEventListener('submit', function (event) {
        var type = selectedType();
        var settings = config[type] || { required_fields: [] };
        var valid = !!facility.value && !!category.value;
        var inputs = { participants: 'expected_participants', quantity: 'quantity', return_date: 'return_date', remarks: 'remarks', schedule: 'schedule', requirements: 'requirements' };
        Object.keys(inputs).forEach(function (field) {
            if (settings.required_fields.indexOf(field) !== -1 && !document.getElementById(inputs[field]).value) valid = false;
        });
        if (settings.required_fields.indexOf('time_range') !== -1) {
            var start = document.getElementById('start_time').value;
            var end = document.getElementById('end_time').value;
            valid = valid && !!start && !!end && new Date(start) < new Date(end);
        }
        if (!valid) event.preventDefault();
    });
});
