/**
 * row-menu.js
 * Generic "meatball" row-action menu + View Details modal controller,
 * shared by Contract Management, Visitor Management, and Legal
 * Management. Same portal/positioning technique already used for
 * Facilities Reservation's "All Reservations" table
 * (public/js/reservation.js's .t8-res-menu-* handling) and the
 * Scheduled Visits table (public/js/visitor.js) - generalized here
 * under a .t8-row- prefix so it isn't duplicated per module.
 *
 * Markup contract:
 *   <div class="t8-row-menu">
 *     <button type="button" class="t8-row-menu-trigger"
 *             data-detail-modal="t8SomeDetailModal"
 *             data-ref="..." data-title="..." data-status="...">
 *       <i class="fa-solid fa-ellipsis-vertical"></i>
 *     </button>
 *     <div class="t8-row-menu-panel" role="menu">
 *       <button type="button" class="t8-row-menu-item t8-row-view-details">View Details</button>
 *       <button type="button" class="t8-row-menu-item t8-row-copy-ref" data-copy="REF-1">Copy Reference</button>
 *       ... other menu items/forms ...
 *     </div>
 *   </div>
 *
 *   <dialog id="t8SomeDetailModal" class="t8-detail-modal">
 *     ...
 *     <strong data-detail-field="title">—</strong>          <!-- auto-filled from trigger.dataset.title -->
 *     <div data-detail-wrap="remarks" hidden>...</div>       <!-- auto shown/hidden based on truthiness -->
 *     <button type="button" data-close-detail-modal>Close</button>
 *   </dialog>
 *
 * Every data-* attribute on the trigger (other than detailModal) is
 * matched by key against [data-detail-field="key"] /
 * [data-detail-wrap="key"] elements inside the target modal - no
 * per-page fill script needed for the common case.
 */
(function () {
    var openPanel = null;
    var openTrigger = null;
    var placeholder = null;

    function closeOpenMenu() {
        if (!openPanel) return;
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

    function positionMenu(panel, trigger) {
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

    function openMenuFor(trigger) {
        var wrap = trigger.closest('.t8-row-menu');
        var panel = wrap ? wrap.querySelector('.t8-row-menu-panel') : null;
        if (!panel) return;

        if (openPanel === panel) {
            closeOpenMenu();
            return;
        }
        closeOpenMenu();

        placeholder = document.createComment('t8-row-menu-slot');
        panel.parentNode.insertBefore(placeholder, panel);
        document.body.appendChild(panel);

        panel.classList.add('t8-portal-open');
        positionMenu(panel, trigger);
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { panel.classList.add('t8-portal-visible'); });
        });

        openPanel = panel;
        openTrigger = trigger;
        trigger.setAttribute('aria-expanded', 'true');
        trigger.classList.add('is-active');
    }

    function initTriggers(scope) {
        (scope || document).querySelectorAll('.t8-row-menu-trigger').forEach(function (trigger) {
            if (trigger.dataset.t8RowMenuBound === '1') return;
            trigger.dataset.t8RowMenuBound = '1';
            trigger.setAttribute('aria-haspopup', 'true');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.addEventListener('click', function (e) {
                e.stopPropagation();
                openMenuFor(trigger);
            });
        });
    }

    document.addEventListener('click', function (e) {
        if (!openPanel) return;
        var clickedInsidePanel = openPanel.contains(e.target);
        var clickedTrigger = openTrigger && openTrigger.contains(e.target);
        if (!clickedInsidePanel && !clickedTrigger) closeOpenMenu();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeOpenMenu(); });
    window.addEventListener('resize', function () { if (openPanel && openTrigger) positionMenu(openPanel, openTrigger); });
    window.addEventListener('scroll', function () { if (openPanel && openTrigger) positionMenu(openPanel, openTrigger); }, true);

    // ---- "Copy Reference" ----
    document.addEventListener('click', function (e) {
        var copyBtn = e.target.closest('.t8-row-copy-ref');
        if (!copyBtn) return;
        var ref = copyBtn.getAttribute('data-copy') || '';
        if (navigator.clipboard && ref) {
            navigator.clipboard.writeText(ref).catch(function () {});
        }
        var original = copyBtn.innerHTML;
        copyBtn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
        setTimeout(function () { copyBtn.innerHTML = original; }, 1400);
        closeOpenMenu();
    });

    // ---- "View Details" -> auto-fill the target <dialog> from the
    //      active trigger's data-* attributes ----
    function fillDetailModal(modal, trigger) {
        var data = trigger.dataset;
        Object.keys(data).forEach(function (key) {
            if (key === 'detailModal' || key === 't8RowMenuBound') return;
            var value = data[key];
            var field = modal.querySelector('[data-detail-field="' + key + '"]');
            if (field) field.textContent = value && value !== '' ? value : '—';
            var wrap = modal.querySelector('[data-detail-wrap="' + key + '"]');
            if (wrap) wrap.hidden = !value;
        });
    }

    function activeTriggerFor(el) {
        if (openPanel && openPanel.contains(el) && openTrigger) {
            return openTrigger;
        }
        var wrap = el.closest('.t8-row-menu');
        return wrap ? wrap.querySelector('.t8-row-menu-trigger') : null;
    }

    document.addEventListener('click', function (e) {
        var viewBtn = e.target.closest('.t8-row-view-details');
        if (!viewBtn) return;
        var trigger = activeTriggerFor(viewBtn);
        if (!trigger || !trigger.dataset.detailModal) return;
        var modal = document.getElementById(trigger.dataset.detailModal);
        if (!modal) return;

        fillDetailModal(modal, trigger);
        closeOpenMenu();
        if (typeof modal.showModal === 'function') modal.showModal();
    });

    // ---- Close buttons / backdrop click for any .t8-detail-modal ----
    document.addEventListener('click', function (e) {
        var closeBtn = e.target.closest('[data-close-detail-modal]');
        if (closeBtn) {
            var modal = closeBtn.closest('.t8-detail-modal');
            if (modal && typeof modal.close === 'function') modal.close();
        }
    });
    document.querySelectorAll('.t8-detail-modal').forEach(function (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal && typeof modal.close === 'function') modal.close();
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        initTriggers(document);
    });

    window.T8RowMenu = { init: initTriggers, close: closeOpenMenu };
})();
