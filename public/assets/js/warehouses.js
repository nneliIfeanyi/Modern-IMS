import { apiRequest, getCurrentUser } from './auth.js';
import { confirmAction } from './ui.js';

const state = {
    search: '',
    editingId: null,
};

const els = {
    search: document.getElementById('warehouse-search'),
    tableBody: document.getElementById('warehouses-table-body'),
    meta: document.getElementById('warehouses-meta'),
    openModalBtn: document.getElementById('open-warehouse-modal-btn'),
    modalTitle: document.getElementById('warehouseModalTitle'),
    form: document.getElementById('warehouse-form'),
    saveBtn: document.getElementById('save-warehouse-btn'),
    toastEl: document.getElementById('app-toast'),
    toastBody: document.getElementById('app-toast-body'),
};

const modalEl = document.getElementById('warehouseModal');
const modal = new bootstrap.Modal(modalEl);
const toast = new bootstrap.Toast(els.toastEl);

function showToast(message, tone = 'success') {
    els.toastBody.textContent = message;
    els.toastEl.className = `toast align-items-center text-bg-${tone} border-0`;
    toast.show();
}

function clearFieldErrors() {
    const fields = els.form.querySelectorAll('input');
    fields.forEach((field) => {
        field.classList.remove('is-invalid');
        const feedback = field.parentElement?.querySelector('.invalid-feedback');
        if (feedback) {
            feedback.textContent = '';
        }
    });
}

function applyFieldErrors(fieldErrors = {}) {
    Object.entries(fieldErrors).forEach(([key, msg]) => {
        const field = els.form.querySelector(`[name="${key}"]`);
        if (!field) {
            return;
        }

        field.classList.add('is-invalid');
        const feedback = field.parentElement?.querySelector('.invalid-feedback');
        if (feedback) {
            feedback.textContent = msg;
        }
    });
}

function getFormPayload() {
    return {
        name: document.getElementById('warehouse-name').value.trim(),
        location: document.getElementById('warehouse-location').value.trim(),
    };
}

function validateClient(payload) {
    const errors = {};

    if (!payload.name) {
        errors.name = 'Warehouse name is required.';
    }
    if (payload.name.length > 100) {
        errors.name = 'Warehouse name must not exceed 100 characters.';
    }
    if (payload.location && payload.location.length > 190) {
        errors.location = 'Location must not exceed 190 characters.';
    }

    return errors;
}

function resetForm() {
    state.editingId = null;
    els.modalTitle.textContent = 'Add Warehouse';
    els.saveBtn.textContent = 'Save warehouse';
    els.form.reset();
    clearFieldErrors();
}

function setEditMode(warehouse) {
    state.editingId = warehouse.id;
    els.modalTitle.textContent = `Edit Warehouse #${warehouse.id}`;
    els.saveBtn.textContent = 'Update warehouse';

    document.getElementById('warehouse-name').value = warehouse.name || '';
    document.getElementById('warehouse-location').value = warehouse.location || '';
}

function createActionButton(label, variant = 'outline-secondary') {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `btn btn-${variant} btn-sm`;
    btn.textContent = label;
    return btn;
}

function renderWarehouses(items = []) {
    if (items.length === 0) {
        els.tableBody.innerHTML = '<tr><td colspan="3" class="text-muted">No warehouses found.</td></tr>';
        els.meta.textContent = 'Showing 0 warehouses';
        return;
    }

    els.tableBody.innerHTML = '';

    items.forEach((warehouse) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${warehouse.name}</td>
            <td>${warehouse.location || '-'}</td>
            <td class="text-end"></td>
        `;

        const actions = tr.querySelector('td:last-child');
        actions.classList.add('d-flex', 'justify-content-end', 'gap-2');

        const editBtn = createActionButton('Edit');
        editBtn.addEventListener('click', () => {
            setEditMode(warehouse);
            clearFieldErrors();
            modal.show();
        });

        const deleteBtn = createActionButton('Delete', 'outline-danger');
        deleteBtn.addEventListener('click', async () => {
            const ok = await confirmAction({
                title: 'Delete warehouse',
                message: `Delete ${warehouse.name}? This action cannot be undone.`,
                confirmLabel: 'Delete warehouse',
            });
            if (!ok) {
                return;
            }

            try {
                await apiRequest(`/warehouses/${warehouse.id}`, { method: 'DELETE' });
                showToast('Warehouse deleted.');
                await loadWarehouses();
            } catch (error) {
                showToast(error.message || 'Delete failed.', 'danger');
            }
        });

        actions.append(editBtn, deleteBtn);
        els.tableBody.appendChild(tr);
    });

    els.meta.textContent = `Showing ${items.length} warehouse${items.length > 1 ? 's' : ''}`;
}

async function loadWarehouses() {
    const params = new URLSearchParams();
    if (state.search) {
        params.set('search', state.search);
    }

    const suffix = params.toString() ? `?${params.toString()}` : '';

    try {
        const response = await apiRequest(`/warehouses${suffix}`, { method: 'GET' });
        renderWarehouses(response.data || []);
    } catch (error) {
        els.tableBody.innerHTML = `<tr><td colspan="3" class="text-danger">${error.message || 'Failed to load warehouses.'}</td></tr>`;
        els.meta.textContent = 'Showing 0 warehouses';
    }
}

function bindEvents() {
    let searchTimer = null;

    els.search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(async () => {
            state.search = els.search.value.trim();
            await loadWarehouses();
        }, 300);
    });

    els.openModalBtn.addEventListener('click', () => {
        resetForm();
        modal.show();
    });

    els.form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFieldErrors();

        const payload = getFormPayload();
        const errors = validateClient(payload);

        if (Object.keys(errors).length > 0) {
            applyFieldErrors(errors);
            return;
        }

        try {
            if (state.editingId === null) {
                await apiRequest('/warehouses', {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
                showToast('Warehouse created.');
            } else {
                await apiRequest(`/warehouses/${state.editingId}`, {
                    method: 'PUT',
                    body: JSON.stringify(payload),
                });
                showToast('Warehouse updated.');
            }

            modal.hide();
            resetForm();
            await loadWarehouses();
        } catch (error) {
            const fieldErrors = error?.response?.data?.fields || {};
            if (Object.keys(fieldErrors).length > 0) {
                applyFieldErrors(fieldErrors);
            }
            showToast(error.message || 'Save failed.', 'danger');
        }
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        resetForm();
    });
}

async function init() {
    try {
        await getCurrentUser();
    } catch (error) {
        window.location.href = 'login.html';
        return;
    }

    bindEvents();
    await loadWarehouses();
}

init();
