/**
 * facilities.js
 *
 * FIX (Add Location):
 *   - The inline "+ Add Location" panel (nested inside the Location
 *     dropdown, itself inside the main facility <form>) is replaced
 *     with a standalone <dialog> (#t8LocationAddModal) that lives as
 *     a sibling of the facility form in the markup (see
 *     modules/facilities/index.php). It opens/closes like the other
 *     <dialog> modals already used in this app.
 *   - The AJAX submit now reads the response as text first and tries
 *     to JSON.parse it, so a non-JSON response (e.g. a stray HTML
 *     page from a misconfigured endpoint) surfaces as a clear,
 *     friendly error message in the modal instead of an uncaught
 *     "Unexpected token '<' ... is not valid JSON" exception. The
 *     underlying cause of that exact error was fixed server-side in
 *     modules/facilities/index.php's `location_create` action (it now
 *     discards the front controller's already-buffered HTML before
 *     writing the JSON response) — this is defense in depth on top of
 *     that fix, not a replacement for it.
 */
document.addEventListener('DOMContentLoaded', () => {
    const dropdown = document.getElementById('t8LocationDropdown');

    if (!dropdown) {
        return;
    }

    const trigger = document.getElementById('t8LocationTrigger');
    const triggerText = document.getElementById('t8LocationTriggerText');
    const panel = document.getElementById('t8LocationPanel');
    const hiddenInput = document.getElementById('location');
    const options = document.getElementById('t8LocationOptions');

    const addButton = document.getElementById('t8LocationAdd');
    const modal = document.getElementById('t8LocationAddModal');
    const modalCloseBtn = document.getElementById('t8LocationAddModalClose');
    const modalCancelBtn = document.getElementById('t8LocationAddCancel');
    const modalForm = document.getElementById('t8LocationAddForm');
    const addInput = document.getElementById('t8LocationAddInput');
    const addError = document.getElementById('t8LocationAddError');
    const addSubmit = document.getElementById('t8LocationAddSubmit');

    const createUrl = dropdown.dataset.createUrl;
    const csrfToken = dropdown.dataset.csrf;

    function openDropdown() {
        panel.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
    }

    function closeDropdown() {
        panel.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
    }

    trigger.addEventListener('click', () => {
        if (panel.hidden) {
            openDropdown();
        } else {
            closeDropdown();
        }
    });

    function selectLocation(name) {
        hiddenInput.value = name;
        triggerText.textContent = name;

        closeDropdown();

        document.querySelectorAll('.t8-location-option').forEach(option => {
            option.classList.toggle(
                'selected',
                option.dataset.value === name
            );
        });
    }

    options.addEventListener('click', event => {
        const option = event.target.closest('.t8-location-option');

        if (!option) {
            return;
        }

        selectLocation(option.dataset.value);
    });

    // ---- "+ Add Location" modal ----------------------------------

    function resetAddError() {
        addError.hidden = true;
        addError.textContent = '';
    }

    function showAddError(message) {
        addError.textContent = message;
        addError.hidden = false;
    }

    function openModal() {
        if (!modal) {
            return;
        }
        closeDropdown();
        resetAddError();
        addInput.value = '';

        if (typeof modal.showModal === 'function') {
            modal.showModal();
        } else {
            // Extremely old browsers without <dialog> support — fall
            // back to just showing it as a plain overlay.
            modal.setAttribute('open', '');
        }

        window.setTimeout(() => addInput.focus(), 30);
    }

    function closeModal() {
        if (!modal) {
            return;
        }
        if (typeof modal.close === 'function' && modal.open) {
            modal.close();
        } else {
            modal.removeAttribute('open');
        }
    }

    if (addButton) {
        addButton.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            openModal();
        });
    }

    if (modalCloseBtn) {
        modalCloseBtn.addEventListener('click', closeModal);
    }
    if (modalCancelBtn) {
        modalCancelBtn.addEventListener('click', closeModal);
    }

    if (modal) {
        // Click on the backdrop (the <dialog> element itself, outside
        // its rendered box) closes it — same pattern already used by
        // #t8OnsiteVisitorModal in public/js/visitor.js.
        modal.addEventListener('click', event => {
            if (event.target === modal) {
                closeModal();
            }
        });

        // Escape is handled natively by <dialog> (fires 'cancel' then
        // 'close') — just clear any leftover error state on close.
        modal.addEventListener('close', resetAddError);
    }

    if (modalForm) {
        modalForm.addEventListener('submit', async event => {
            event.preventDefault();

            const name = addInput.value.trim();
            resetAddError();

            if (!name) {
                showAddError('Please enter a location name.');
                addInput.focus();
                return;
            }

            addSubmit.disabled = true;
            const originalLabel = addSubmit.innerHTML;
            addSubmit.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding...';

            try {
                const body = new URLSearchParams();

                body.append('name', name);
                body.append('csrf_token', csrfToken);

                const response = await fetch(createUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: body.toString()
                });

                const rawText = await response.text();
                let data;
                try {
                    data = JSON.parse(rawText);
                } catch (parseError) {
                    throw new Error('Something went wrong reaching the server. Please try again.');
                }

                if (!response.ok || data.error) {
                    throw new Error(data.error || 'Could not add this location.');
                }

                // Add the new location to the dropdown (or reuse the
                // existing option if it was already there).
                let option = document.querySelector(
                    `.t8-location-option[data-value="${CSS.escape(data.name)}"]`
                );

                if (!option) {
                    option = document.createElement('li');

                    option.className = 't8-location-option';
                    option.setAttribute('role', 'option');
                    option.dataset.value = data.name;
                    option.textContent = data.name;

                    options.appendChild(option);
                }

                // Select it immediately, then close the modal.
                selectLocation(data.name);
                closeModal();

            } catch (error) {
                showAddError(error.message);
            } finally {
                addSubmit.disabled = false;
                addSubmit.innerHTML = originalLabel;
            }
        });
    }

    // Don't allow clicking inside the dropdown panel to accidentally
    // toggle the main dropdown closed/open.
    panel.addEventListener('click', event => {
        event.stopPropagation();
    });

    // Clicking anywhere else on the page closes the dropdown.
    document.addEventListener('click', event => {
        if (!dropdown.contains(event.target)) {
            closeDropdown();
        }
    });
});
