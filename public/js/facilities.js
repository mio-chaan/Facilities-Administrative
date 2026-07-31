/**
 * facilities.js
 * Enhances the Facility Management form by making Location dependent on
 * the selected Facility Type.
 */

document.addEventListener('DOMContentLoaded', function () {
    var facilityTypeEl = document.getElementById('facility_type');
    var locationEl = document.getElementById('location');
    if (!facilityTypeEl || !locationEl) {
        return;
    }

    var locationMap = window.t8FacilityLocationMap || {};
    var selectedLocation = locationEl.value;

    function updateLocationOptions() {
        var facilityType = facilityTypeEl.value;
        locationEl.innerHTML = '';

        if (!facilityType) {
            locationEl.disabled = true;
            var promptOption = document.createElement('option');
            promptOption.value = '';
            promptOption.textContent = 'Select a facility type first';
            promptOption.disabled = true;
            promptOption.selected = true;
            locationEl.appendChild(promptOption);
            return;
        }

        var options = locationMap[facilityType] || [];
        locationEl.disabled = false;

        var promptOption = document.createElement('option');
        promptOption.value = '';
        promptOption.textContent = 'Select a location';
        promptOption.disabled = true;
        promptOption.selected = true;
        locationEl.appendChild(promptOption);

        options.forEach(function (locationValue) {
            var option = document.createElement('option');
            option.value = locationValue;
            option.textContent = locationValue;
            if (locationValue === selectedLocation) {
                option.selected = true;
                promptOption.selected = false;
            }
            locationEl.appendChild(option);
        });
    }

    function updateCapacityLabel() {
        var capacityLabelEl = document.querySelector("label[for='capacity']");
        if (!capacityLabelEl) {
            return;
        }

        var facilityType = facilityTypeEl.value;
        var quantityTypes = ['Equipment', 'Asset', 'Utility'];
        capacityLabelEl.textContent = quantityTypes.includes(facilityType) ? 'Quantity' : 'Capacity';
    }

    facilityTypeEl.addEventListener('change', function () {
        selectedLocation = '';
        updateLocationOptions();
        updateCapacityLabel();
    });

    updateLocationOptions();
    updateCapacityLabel();
});
