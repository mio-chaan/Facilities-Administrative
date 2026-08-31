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
    const addPanel = document.getElementById('t8LocationAddPanel');
    const addClose = document.getElementById('t8LocationAddClose');
    const addCancel = document.getElementById('t8LocationAddCancel');
    const addSubmit = document.getElementById('t8LocationAddSubmit');
    const addInput = document.getElementById('t8LocationAddInput');
    const addError = document.getElementById('t8LocationAddError');

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
        closeAddPanel();

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

    function openAddPanel() {
        addPanel.hidden = false;
        addInput.value = '';
        addError.hidden = true;

        setTimeout(() => {
            addInput.focus();
        }, 50);
    }

    function closeAddPanel() {
        addPanel.hidden = true;
        addError.hidden = true;
    }

    addButton.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();

        openAddPanel();
    });

    addClose.addEventListener('click', closeAddPanel);
    addCancel.addEventListener('click', closeAddPanel);

    addSubmit.addEventListener('click', async () => {
        const name = addInput.value.trim();

        addError.hidden = true;

        if (!name) {
            addError.textContent = 'Please enter a location name.';
            addError.hidden = false;
            addInput.focus();
            return;
        }

        addSubmit.disabled = true;
        addSubmit.innerHTML =
            '<i class="fa-solid fa-spinner fa-spin"></i> Adding...';

        try {
            const body = new URLSearchParams();

            body.append('name', name);
            body.append('csrf_token', csrfToken);

            const response = await fetch(createUrl, {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            });

            const data = await response.json();

            if (!response.ok || data.error) {
                throw new Error(
                    data.error || 'Could not add this location.'
                );
            }

            // Add the new location to the dropdown.
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

            // Select it immediately.
            selectLocation(data.name);

        } catch (error) {
            addError.textContent = error.message;
            addError.hidden = false;

        } finally {
            addSubmit.disabled = false;
            addSubmit.innerHTML =
                '<i class="fa-solid fa-plus"></i> Add Location';
        }
    });

    addInput.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            addSubmit.click();
        }

        if (event.key === 'Escape') {
            closeAddPanel();
        }
    });

    // Don't allow clicking inside the dropdown panel to
    // accidentally toggle the main dropdown.
    panel.addEventListener('click', event => {
        event.stopPropagation();
    });
});
