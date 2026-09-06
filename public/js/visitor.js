document.addEventListener('DOMContentLoaded', function () {
    var type = document.getElementById('visitor_type');
    var otherType = document.getElementById('visitor_type_other');
    var purpose = document.getElementById('purpose');
    if (!type || !purpose) return;
    var form = type.form;
    var map = JSON.parse(type.getAttribute('data-purpose-map') || '{}');
    function applyOtherType() {
        var isOther = type.value === 'Other';
        if (otherType) {
            otherType.hidden = !isOther;
            otherType.required = isOther;
            if (!isOther) otherType.value = '';
        }
        if (form) form.classList.toggle('t8-visitor-other-active', isOther);
    }
    function applyPurpose() {
        var value = map[type.value] || '';
        if (value) purpose.value = value;
    }
    type.addEventListener('change', function () {
        applyOtherType();
        applyPurpose();
    });
    applyOtherType();
    if (!purpose.value) applyPurpose();

    var scheduledDate = document.getElementById('scheduled_date');
    var arrivingNow = document.getElementById('arriving_now');
    if (scheduledDate && arrivingNow) {
        scheduledDate.form.addEventListener('submit', function (event) {
            if (!arrivingNow.checked && scheduledDate.value && new Date(scheduledDate.value) <= new Date()) {
                event.preventDefault();
                alert('Scheduled visit date and time must be in the future.');
                scheduledDate.focus();
            }
        });
    }

    var onsiteModal = document.getElementById('t8OnsiteVisitorModal');
    if (onsiteModal) {
        document.querySelectorAll('[data-open-onsite-modal]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (typeof onsiteModal.showModal === 'function') {
                    onsiteModal.showModal();
                }
            });
        });

        document.querySelectorAll('[data-close-onsite-modal]').forEach(function (button) {
            button.addEventListener('click', function () {
                onsiteModal.close();
            });
        });

        onsiteModal.addEventListener('click', function (event) {
            if (event.target === onsiteModal) {
                onsiteModal.close();
            }
        });
    }
});

/**
 * NOTE: the Scheduled/Upcoming Visits meatball menu used to be
 * implemented here as a page-specific portal controller
 * (.t8-visitor-menu-trigger / .t8-visitor-menu-panel). It has been
 * replaced by the shared row menu + View Details system used by
 * Contract, Visitor, and Legal Management alike - see
 * public/js/row-menu.js and public/css/row-menu.css. All three
 * visitor tables (Scheduled, Currently On-Site, Visitor Logs) now
 * render their action menus with modules/visitor/index.php's
 * t8_visitor_render_menu() using the generic `.t8-row-menu-*`
 * markup, so nothing page-specific is needed here for that anymore.
 */
