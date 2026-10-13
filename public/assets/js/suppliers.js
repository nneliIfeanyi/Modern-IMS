import { apiRequest, getCurrentUser } from './auth.js';
import { confirmAction } from './ui.js';

const state = {
    search: '',
    editingId: null,
};

const els = {
    search: document.getElementById('supplier-search'),
    tableBody: document.getElementById('suppliers-table-body'),
    meta: document.getElementById('suppliers-meta'),
    openModalBtn: document.getElementById('open-supplier-modal-btn'),
    modalTitle: document.getElementById('supplierModalTitle'),
    form: document.getElementById('supplier-form'),
    saveBtn: document.getElementById('save-supplier-btn'),
    toastEl: document.getElementById('app-toast'),
    toastBody: document.getElementById('app-toast-body'),
};

const modalEl = document.getElementById('supplierModal');
const modal = new bootstrap.Modal(modalEl);
const toast = new bootstrap.Toast(els.toastEl);

function formatNgn(value) {
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
        minimumFractionDigits: 2,
    }).format(Number(value || 0));
}

function showToast(message, tone = 'success') {
    els.toastBody.textContent = message;
    els.toastEl.className = `toast align-items-center text-bg-${tone} border-0`;
    toast.show();
}

function clearFieldErrors() {
    const fields = els.form.querySelectorAll('input, textarea');
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
        name: document.getElementById('supplier-name').value.trim(),
        contact_person: document.getElementById('supplier-contact').value.trim(),
        phone: document.getElementById('supplier-phone').value.trim(),
        email: document.getElementById('supplier-email').value.trim(),
        address: document.getElementById('supplier-address').value.trim(),
    };
}

function validateClient(payload) {
    const errors = {};

    if (!payload.name) {
        errors.name = 'Supplier name is required.';
    }
    if (payload.name.length > 150) {
        errors.name = 'Supplier name must not exceed 150 characters.';
    }
    if (payload.contact_person && payload.contact_person.length > 120) {
        errors.contact_person = 'Contact person must not exceed 120 characters.';
    }
    if (payload.phone && payload.phone.length > 40) {
        errors.phone = 'Phone must not exceed 40 characters.';
    }
    if (payload.email && payload.email.length > 190) {
        errors.email = 'Email must not exceed 190 characters.';
    }
    if (payload.email && !/^\S+@\S+\.\S+$/.test(payload.email)) {
        errors.email = 'Email must be a valid email address.';
    }
    if (payload.address && payload.address.length > 255) {
        errors.address = 'Address must not exceed 255 characters.';
    }

    return errors;
}

function resetForm() {
    state.editingId = null;
    els.modalTitle.textContent = 'Add Supplier';
    els.saveBtn.textContent = 'Save supplier';
    els.form.reset();
    clearFieldErrors();
}

function setEditMode(supplier) {
    state.editingId = supplier.id;
    els.modalTitle.textContent = `Edit Supplier #${supplier.id}`;
    els.saveBtn.textContent = 'Update supplier';

    document.getElementById('supplier-name').value = supplier.name || '';
    document.getElementById('supplier-contact').value = supplier.contact_person || '';
    document.getElementById('supplier-phone').value = supplier.phone || '';
    document.getElementById('supplier-email').value = supplier.email || '';
    document.getElementById('supplier-address').value = supplier.address || '';
}

function createActionButton(label, variant = 'outline-secondary') {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `btn btn-${variant} btn-sm`;
    btn.textContent = label;
    return btn;
}

function renderSuppliers(items = []) {
    if (items.length === 0) {
        els.tableBody.innerHTML = '<tr><td colspan="7" class="text-muted">No suppliers found.</td></tr>';
        els.meta.textContent = 'Showing 0 suppliers';
        return;
    }

    els.tableBody.innerHTML = '';

    items.forEach((supplier) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${supplier.name}</td>
            <td>${supplier.contact_person || '-'}</td>
            <td>${supplier.phone || '-'}</td>
            <td>${supplier.email || '-'}</td>
            <td><span class="badge text-bg-light">${Number(supplier.open_po_count || 0)} open</span></td>
            <td>${formatNgn(supplier.total_spend_ytd || 0)}</td>
            <td class="text-end"></td>
        `;

        const actions = tr.querySelector('td:last-child');
        actions.classList.add('d-flex', 'justify-content-end', 'gap-2');

        const editBtn = createActionButton('Edit');
        editBtn.addEventListener('click', () => {
            setEditMode(supplier);
            clearFieldErrors();
            modal.show();
        });

        const deactivateBtn = createActionButton('Deactivate', 'outline-danger');
        deactivateBtn.addEventListener('click', async () => {
            const ok = await confirmAction({
                title: 'Deactivate supplier',
                message: `Deactivate ${supplier.name}? It will no longer appear as active.`,
                confirmLabel: 'Deactivate',
            });
            if (!ok) {
                return;
            }

            try {
                await apiRequest(`/suppliers/${supplier.id}`, { method: 'DELETE' });
                showToast('Supplier deactivated.');
                await loadSuppliers();
            } catch (error) {
                showToast(error.message || 'Deactivate failed.', 'danger');
            }
        });

        const viewPoLink = document.createElement('a');
        viewPoLink.className = 'btn btn-outline-primary btn-sm';
        viewPoLink.href = `purchase-orders.html?supplier_id=${supplier.id}`;
        viewPoLink.textContent = 'View POs';

        actions.append(editBtn, viewPoLink, deactivateBtn);
        els.tableBody.appendChild(tr);
    });

    els.meta.textContent = `Showing ${items.length} supplier${items.length > 1 ? 's' : ''}`;
}

async function loadSuppliers() {
    const params = new URLSearchParams();
    if (state.search) {
        params.set('search', state.search);
    }

    const suffix = params.toString() ? `?${params.toString()}` : '';

    try {
        const response = await apiRequest(`/suppliers${suffix}`, { method: 'GET' });
        renderSuppliers(response.data || []);
    } catch (error) {
        els.tableBody.innerHTML = `<tr><td colspan="7" class="text-danger">${error.message || 'Failed to load suppliers.'}</td></tr>`;
        els.meta.textContent = 'Showing 0 suppliers';
    }
}

function bindEvents() {
    let searchTimer = null;

    els.search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(async () => {
            state.search = els.search.value.trim();
            await loadSuppliers();
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
                await apiRequest('/suppliers', {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
                showToast('Supplier created.');
            } else {
                await apiRequest(`/suppliers/${state.editingId}`, {
                    method: 'PUT',
                    body: JSON.stringify(payload),
                });
                showToast('Supplier updated.');
            }

            modal.hide();
            resetForm();
            await loadSuppliers();
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
    await loadSuppliers();
}

init();
