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
});
