/**
 * validation.js
 * Small, dependency-free client-side validation helpers.
 * Client-side checks are UX only - every module MUST also validate
 * and use prepared statements server-side (see app/includes).
 */

var T8Validate = {
    required: function (value) {
        return value !== null && value.toString().trim() !== "";
    },

    dateRangeValid: function (startValue, endValue) {
        if (!startValue || !endValue) return true;
        return new Date(startValue) < new Date(endValue);
    },

    maxLength: function (value, max) {
        return value.toString().length <= max;
    },

    showError: function (fieldEl, message) {
        var wrapper = fieldEl.closest(".t8-field");
        if (!wrapper) return;
        wrapper.classList.add("t8-field-error");
        var existing = wrapper.querySelector(".t8-error-text");
        if (existing) existing.remove();
        var errorEl = document.createElement("div");
        errorEl.className = "t8-error-text";
        errorEl.textContent = message;
        wrapper.appendChild(errorEl);
    },

    clearError: function (fieldEl) {
        var wrapper = fieldEl.closest(".t8-field");
        if (!wrapper) return;
        wrapper.classList.remove("t8-field-error");
        var existing = wrapper.querySelector(".t8-error-text");
        if (existing) existing.remove();
    }
};
