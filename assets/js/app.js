/**
 * StockWise — Core Frontend JavaScript
 * Handles notifications, modals, helpers, and UI utilities.
 */

'use strict';

// ── Toast Notifications ─────────────────────────────────────────
function showToast(message, type = 'info') {
    const toastEl = document.getElementById('liveToast');
    if (!toastEl) return;

    const toastMsg = document.getElementById('toastMessage');
    const toastIcon = document.getElementById('toastIcon');

    // Reset bg classes
    toastEl.className = 'toast align-items-center text-white border-0 shadow';

    let bgClass = 'bg-primary';
    let iconClass = 'bi-info-circle-fill';

    switch (type) {
        case 'success':
            bgClass = 'bg-success';
            iconClass = 'bi-check-circle-fill';
            break;
        case 'danger':
        case 'error':
            bgClass = 'bg-danger';
            iconClass = 'bi-exclamation-circle-fill';
            break;
        case 'warning':
            bgClass = 'bg-warning text-dark';
            iconClass = 'bi-exclamation-triangle-fill';
            break;
        case 'info':
        default:
            bgClass = 'bg-info text-dark';
            iconClass = 'bi-info-circle-fill';
            break;
    }

    toastEl.classList.add(...bgClass.split(' '));
    if (toastIcon) {
        toastIcon.className = `bi ${iconClass} fs-5`;
    }
    if (toastMsg) {
        toastMsg.textContent = message;
    }

    const toast = new bootstrap.Toast(toastEl, { delay: 4000 });
    toast.show();
}

// ── Global Confirm Modal ────────────────────────────────────────
let confirmCallback = null;

function confirmAction(message, onConfirm, title = 'Confirm Action', isDanger = true) {
    const modalEl = document.getElementById('globalConfirmModal');
    if (!modalEl) {
        if (confirm(message)) {
            onConfirm();
        }
        return;
    }

    const bodyEl = document.getElementById('globalConfirmModalBody');
    const titleEl = document.getElementById('globalConfirmModalLabel');
    const okBtn = document.getElementById('globalConfirmModalOkBtn');

    if (bodyEl) bodyEl.textContent = message;
    if (titleEl) {
        titleEl.innerHTML = `<i class="bi ${isDanger ? 'bi-exclamation-triangle-fill text-danger' : 'bi-question-circle-fill text-primary'}"></i> ${title}`;
    }
    if (okBtn) {
        okBtn.className = isDanger ? 'btn btn-danger px-4' : 'btn btn-primary px-4';
    }

    confirmCallback = onConfirm;

    const modal = new bootstrap.Modal(modalEl);
    modal.show();
}

document.addEventListener('DOMContentLoaded', function () {
    const okBtn = document.getElementById('globalConfirmModalOkBtn');
    if (okBtn) {
        okBtn.addEventListener('click', function () {
            const modalEl = document.getElementById('globalConfirmModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();

            if (typeof confirmCallback === 'function') {
                confirmCallback();
                confirmCallback = null;
            }
        });
    }

    // Initialize Bootstrap tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Client-side quick filter on data tables
    const tableSearchInputs = document.querySelectorAll('.js-table-search');
    tableSearchInputs.forEach(input => {
        const targetSelector = input.getAttribute('data-target');
        const targetTable = document.querySelector(targetSelector);
        if (!targetTable) return;

        input.addEventListener('input', function () {
            const query = this.value.toLowerCase().trim();
            const rows = targetTable.querySelectorAll('tbody tr');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        });
    });

    // Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });
});

// ── Currency Formatter ──────────────────────────────────────────
function formatCurrency(amount) {
    const num = parseFloat(amount) || 0;
    return '$' + num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
