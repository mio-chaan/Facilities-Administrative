/** Facility-type-driven reservation form behaviour. */
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('t8ReservationForm');
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
