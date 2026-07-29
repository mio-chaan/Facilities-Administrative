/**
 * reservation.js
 * Milestone 3 (Facilities Reservation) - client-side UX only.
 * Loaded conditionally by templates/footer.php when $page === 'reservation',
 * after public/js/validation.js (T8Validate helpers).
 *
 * Every check here is UX sugar - modules/reservation/index.php
 * re-validates everything server-side (facility required, start <
 * end, start not in the past, equipment quantity bounds, overlap
 * check) before touching the database.
 */

document.addEventListener("DOMContentLoaded", function () {
    var form = document.getElementById("t8ReservationForm");
    if (!form) return;

    var startInput = document.getElementById("start_time");
    var endInput = document.getElementById("end_time");

    // Equipment rows: quantity input only usable once its checkbox is
    // checked, and clamped to the "of N available" max on that input.
    var equipmentRows = form.querySelectorAll(".t8-equipment-row");
    equipmentRows.forEach(function (row) {
        var checkbox = row.querySelector('input[type="checkbox"]');
        var qtyInput = row.querySelector(".t8-equipment-qty");
        if (!checkbox || !qtyInput) return;

        var syncQtyState = function () {
            qtyInput.disabled = !checkbox.checked;
        };
        syncQtyState();
        checkbox.addEventListener("change", syncQtyState);

        qtyInput.addEventListener("change", function () {
            var max = parseInt(qtyInput.getAttribute("max"), 10);
            var min = parseInt(qtyInput.getAttribute("min"), 10) || 1;
            var value = parseInt(qtyInput.value, 10);
            if (isNaN(value) || value < min) value = min;
            if (!isNaN(max) && value > max) value = max;
            qtyInput.value = value;
        });
    });

    form.addEventListener("submit", function (event) {
        var valid = true;

        if (!T8Validate.required(form.facility_id.value)) {
            T8Validate.showError(form.facility_id, "Please select a facility.");
            valid = false;
        } else {
            T8Validate.clearError(form.facility_id);
        }

        if (!T8Validate.required(startInput.value) || !T8Validate.required(endInput.value)) {
            T8Validate.showError(endInput, "Both a start and end time are required.");
            valid = false;
        } else if (!T8Validate.dateRangeValid(startInput.value, endInput.value)) {
            T8Validate.showError(endInput, "End time must be after the start time.");
            valid = false;
        } else {
            T8Validate.clearError(endInput);
        }

        if (!valid) {
            event.preventDefault();
        }
    });
});
