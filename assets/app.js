'use strict';

document.addEventListener('click', (event) => {
    const confirmed = event.target.closest('[data-confirm]');
    if (confirmed && !window.confirm(confirmed.dataset.confirm)) {
        event.preventDefault();
    }
    if (event.target.closest('[data-print]')) {
        window.print();
    }
    const markAll = event.target.closest('[data-mark-all]');
    if (markAll) {
        const form = markAll.closest('form');
        form.querySelectorAll('fieldset.status-options').forEach((group) => {
            if (!group.querySelector('input:checked')) {
                group.querySelector(`input[value="${markAll.dataset.markAll}"]`).checked = true;
            }
        });
    }
    const pickAll = event.target.closest('[data-pick-all]');
    if (pickAll) {
        pickAll.closest('form').querySelectorAll('[data-pick-item]:not([hidden]) input').forEach((box) => {
            box.checked = true;
        });
    }
});

document.querySelectorAll('[data-pick-filter]').forEach((filter) => {
    filter.addEventListener('input', () => {
        const term = filter.value.trim().toLowerCase();
        filter.closest('form').querySelectorAll('[data-pick-item]').forEach((item) => {
            item.hidden = !item.textContent.toLowerCase().includes(term);
        });
    });
});

// Enter in the filter box must not submit the enrol form.
document.querySelectorAll('[data-pick-filter]').forEach((filter) => {
    filter.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
        }
    });
});

document.querySelectorAll('[data-attendance-form]').forEach((form) => {
    const select = form.querySelector('[data-status-select]');
    const sync = () => {
        form.querySelectorAll('[data-time-field]').forEach((field) => {
            field.hidden = select.value !== 'auto';
        });
    };
    select.addEventListener('change', sync);
    sync();
});

const kiosk = document.querySelector('.kiosk');
if (kiosk) {
    // Follows the server's clock, because that is the time that gets recorded.
    const offset = Number(kiosk.dataset.serverTime) - Date.now();
    const clockElement = document.getElementById('kiosk-clock');
    const timeZone = kiosk.dataset.timeZone;
    const format = new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true, timeZone });
    const tick = () => {
        clockElement.textContent = format.format(new Date(Date.now() + offset));
    };
    tick();
    setInterval(tick, 1000);

    const input = document.getElementById('code');
    // A barcode scanner types into whatever has focus, so keep it here.
    document.addEventListener('click', (event) => {
        if (!event.target.closest('a, button')) {
            input.focus();
        }
    });

    const result = document.getElementById('kiosk-result');
    if (result) {
        setTimeout(() => result.remove(), 6000);
    }

    // Reloading keeps the session and the date line current on a kiosk left open all day.
    setInterval(() => {
        if (input.value === '') {
            window.location.reload();
        }
    }, 5 * 60 * 1000);
}
