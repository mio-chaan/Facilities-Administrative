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
 * Scheduled/Upcoming Visits — meatball menu popover.
 *
 * FIX: the panel used to be `position: absolute` inside a `<details>`
 * nested in a `<td>`. Any ancestor's `overflow` (the table wrap, the
 * card) could clip it, and no z-index could fix that. This controller
 * instead PORTALS the panel to <body> on open — moving the actual DOM
 * node itself, forms and CSRF fields included, not a clone — and
 * positions it with `position: fixed`, computed from the trigger
 * button's bounding rect. Fixed positioning is relative to the
 * viewport only, so it can never be clipped by a table/card's
 * overflow setting again. On close, the panel is moved back to
 * exactly where it came from using a placeholder comment node, so the
 * existing PHP-rendered reschedule/cancel forms never need to change.
 */
document.addEventListener('DOMContentLoaded', function () {
    var openPanel = null;
    var openTrigger = null;
    var placeholder = null;
    var visibleTimer = null;

    function closeOpenPanel() {
        if (!openPanel) {
            return;
        }
        clearTimeout(visibleTimer);
        openPanel.classList.remove('t8-portal-open', 't8-portal-visible');
        openPanel.style.top = '';
        openPanel.style.left = '';
        if (placeholder && placeholder.parentNode) {
            placeholder.parentNode.insertBefore(openPanel, placeholder);
            placeholder.parentNode.removeChild(placeholder);
        }
        if (openTrigger) {
            openTrigger.setAttribute('aria-expanded', 'false');
            openTrigger.classList.remove('is-active');
        }
        openPanel = null;
        openTrigger = null;
        placeholder = null;
    }

    function positionPanel(panel, trigger) {
        var margin = 8;
        var rect = trigger.getBoundingClientRect();
        var pw = panel.offsetWidth;
        var ph = panel.offsetHeight;

        var spaceBelow = window.innerHeight - rect.bottom;
        var openUpward = spaceBelow < (ph + margin) && rect.top > (ph + margin);

        var top = openUpward ? (rect.top - ph - margin) : (rect.bottom + margin);
        top = Math.max(margin, Math.min(top, window.innerHeight - ph - margin));

        var left = rect.right - pw;
        left = Math.max(margin, Math.min(left, window.innerWidth - pw - margin));

        panel.style.top = top + 'px';
        panel.style.left = left + 'px';
    }

    function openPanelFor(trigger) {
        var wrap = trigger.closest('.t8-visitor-menu');
        var panel = wrap ? wrap.querySelector('.t8-visitor-menu-panel') : null;
        if (!panel) {
            return;
        }

        if (openPanel === panel) {
            closeOpenPanel();
            return;
        }
        closeOpenPanel();

        placeholder = document.createComment('t8-menu-panel-slot');
        panel.parentNode.insertBefore(placeholder, panel);
        document.body.appendChild(panel);

        panel.classList.add('t8-portal-open');
        positionPanel(panel, trigger);
        // Double rAF so the browser paints at the computed position
        // before fading/sliding in, rather than animating from (0,0).
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                panel.classList.add('t8-portal-visible');
            });
        });

        openPanel = panel;
        openTrigger = trigger;
        trigger.setAttribute('aria-expanded', 'true');
        trigger.classList.add('is-active');

        var firstField = panel.querySelector('input, select, textarea, button');
        if (firstField) {
            firstField.focus({ preventScroll: true });
        }
    }

    document.querySelectorAll('.t8-visitor-menu-trigger').forEach(function (trigger) {
        trigger.setAttribute('aria-haspopup', 'true');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            openPanelFor(trigger);
        });
    });

    document.addEventListener('click', function (e) {
        if (!openPanel) {
            return;
        }
        var clickedInsidePanel = openPanel.contains(e.target);
        var clickedTrigger = openTrigger && openTrigger.contains(e.target);
        if (!clickedInsidePanel && !clickedTrigger) {
            closeOpenPanel();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeOpenPanel();
        }
    });

    window.addEventListener('resize', function () {
        if (openPanel && openTrigger) {
            positionPanel(openPanel, openTrigger);
        }
    });

    // capture:true so this also catches scrolling inside the table's
    // own horizontal-scroll wrap, not just the window.
    window.addEventListener('scroll', function () {
        if (openPanel && openTrigger) {
            positionPanel(openPanel, openTrigger);
        }
    }, true);
});
