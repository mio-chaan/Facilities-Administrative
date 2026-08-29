document.addEventListener('DOMContentLoaded', function () {
    var type = document.getElementById('visitor_type');
    var purpose = document.getElementById('purpose');
    if (!type || !purpose) return;
    var map = JSON.parse(type.getAttribute('data-purpose-map') || '{}');
    function applyPurpose() {
        var value = map[type.value] || '';
        if (value) purpose.value = value;
    }
    type.addEventListener('change', applyPurpose);
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
