import { apiRequest, getCurrentUser } from './auth.js';

const state = {
    typeFilter: 'all',
    warehouseFilter: '',
    search: '',
    dateFrom: '',
    dateTo: '',
    page: 1,
    perPage: 20,
    totalPages: 1,
    selectedMovementType: 'in',
    selectedProduct: null,
    productLookup: new Map(),
    initialWarehouseId: null,
};

const els = {
    typeFilterBtns: document.querySelectorAll('[data-type-filter]'),
    warehouseFilter: document.getElementById('warehouse-filter'),
    movementSearch: document.getElementById('movement-search'),
    dateFrom: document.getElementById('date-from'),
    dateTo: document.getElementById('date-to'),
    tableBody: document.getElementById('movements-table-body'),
    meta: document.getElementById('movements-meta'),
    prevBtn: document.getElementById('movements-prev'),
    nextBtn: document.getElementById('movements-next'),
    openFormBtn: document.getElementById('open-movement-form'),
    form: document.getElementById('movement-form'),
    movementTypeBtns: document.querySelectorAll('[data-movement-type]'),
    productSearch: document.getElementById('movement-product-search'),
    productId: document.getElementById('movement-product-id'),
    productSuggestions: document.getElementById('movement-product-suggestions'),
    warehouseSelect: document.getElementById('movement-warehouse'),
    destinationWrapper: document.getElementById('destination-wrapper'),
    destinationSelect: document.getElementById('movement-destination-warehouse'),
    quantity: document.getElementById('movement-quantity'),
    reference: document.getElementById('movement-reference'),
    notes: document.getElementById('movement-notes'),
    submitBtn: document.getElementById('movement-submit'),
    toastEl: document.getElementById('movement-toast'),
    toastBody: document.getElementById('movement-toast-body'),
};

const canvasEl = document.getElementById('movementFormCanvas');
const movementCanvas = new bootstrap.Offcanvas(canvasEl);
const toast = new bootstrap.Toast(els.toastEl);

function showToast(message, tone = 'success') {
    els.toastBody.textContent = message;
    els.toastEl.className = `toast align-items-center text-bg-${tone} border-0`;
    toast.show();
}

function clearErrors() {
    const nodes = document.querySelectorAll('[data-error-for]');
    nodes.forEach((node) => {
        node.textContent = '';
    });
}

function setErrors(errors = {}) {
    Object.entries(errors).forEach(([key, value]) => {
        const node = document.querySelector(`[data-error-for="${key}"]`);
        if (node) {
            node.textContent = value;
        }
    });
}

function updateTypeButtons(buttons, activeValue, attrName) {
    buttons.forEach((btn) => {
        const isActive = btn.getAttribute(attrName) === activeValue;
        btn.classList.toggle('active', isActive);
    });
}

function formatDateTime(value) {
    const date = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-NG', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

function movementTypeLabel(type) {
    return ({
        in: 'Stock in',
        out: 'Stock out',
        adjustment: 'Adjustment',
        transfer: 'Transfer',
    }[type] || type);
}

function renderMovements(items = [], meta = {}) {
    if (items.length === 0) {
        els.tableBody.innerHTML = '<tr><td colspan="7" class="text-muted">No stock movements found.</td></tr>';
    } else {
        els.tableBody.innerHTML = '';

        items.forEach((item) => {
            const tr = document.createElement('tr');
            const qty = Number(item.quantity || 0);
            const qtyDisplay = qty > 0 ? `+${qty}` : `${qty}`;
            const type = String(item.movement_type || '');
            const badgeTone = type === 'in' ? 'success' : type === 'out' ? 'danger' : type === 'transfer' ? 'primary' : 'secondary';

            tr.innerHTML = `
        <td>${formatDateTime(item.created_at)}</td>
        <td>${item.product_name} <span class="text-muted small">(${item.sku})</span></td>
        <td><span class="badge text-bg-${badgeTone}">${movementTypeLabel(type)}</span></td>
        <td class="fw-semibold">${qtyDisplay}</td>
        <td>${item.warehouse_name}</td>
        <td>${item.reference || '-'}</td>
        <td>${item.created_by_name || '-'}</td>
      `;

            els.tableBody.appendChild(tr);
        });
    }

    const page = Number(meta.page || 1);
    const perPage = Number(meta.per_page || state.perPage);
    const total = Number(meta.total || 0);
    const totalPages = Number(meta.total_pages || 1);

    state.totalPages = totalPages;

    const start = total === 0 ? 0 : (page - 1) * perPage + 1;
    const end = Math.min(page * perPage, total);

    els.meta.textContent = `Showing ${start}-${end} of ${total} movements`;
    els.prevBtn.disabled = page <= 1;
    els.nextBtn.disabled = page >= totalPages;
}

async function loadWarehouses() {
    const response = await apiRequest('/warehouses', { method: 'GET' });
    const warehouses = response.data || [];
    state.initialWarehouseId = warehouses[0]?.id ? Number(warehouses[0].id) : null;

    const filterOptions = ['<option value="">All warehouses</option>'];
    const selectOptions = ['<option value="">Select warehouse</option>'];

    warehouses.forEach((warehouse) => {
        filterOptions.push(`<option value="${warehouse.id}">${warehouse.name}</option>`);
        selectOptions.push(`<option value="${warehouse.id}">${warehouse.name}</option>`);
    });

    els.warehouseFilter.innerHTML = filterOptions.join('');
    els.warehouseSelect.innerHTML = selectOptions.join('');
    els.destinationSelect.innerHTML = selectOptions.join('');
    if (state.initialWarehouseId) {
        els.warehouseSelect.value = String(state.initialWarehouseId);
    }
}

async function loadMovements() {
    const params = new URLSearchParams();
    params.set('page', String(state.page));
    params.set('per_page', String(state.perPage));
    if (state.typeFilter && state.typeFilter !== 'all') {
        params.set('type', state.typeFilter);
    }
    if (state.warehouseFilter) {
        params.set('warehouse_id', state.warehouseFilter);
    }
    if (state.dateFrom) {
        params.set('date_from', state.dateFrom);
    }
    if (state.dateTo) {
        params.set('date_to', state.dateTo);
    }
    if (state.search) {
        params.set('search', state.search);
    }

    try {
        const response = await apiRequest(`/stock-movements?${params.toString()}`, { method: 'GET' });
        renderMovements(response.data.items || [], response.data.meta || {});
    } catch (error) {
        els.tableBody.innerHTML = `<tr><td colspan="7" class="text-danger">${error.message || 'Failed to load movements.'}</td></tr>`;
    }
}

function resetForm() {
    clearErrors();
    state.selectedMovementType = 'in';
    state.selectedProduct = null;
    state.productLookup.clear();

    updateTypeButtons(els.movementTypeBtns, state.selectedMovementType, 'data-movement-type');

    els.productSearch.value = '';
    els.productId.value = '';
    els.quantity.value = '';
    els.reference.value = '';
    els.notes.value = '';
    els.warehouseSelect.value = state.initialWarehouseId ? String(state.initialWarehouseId) : '';
    els.destinationSelect.value = '';
    els.destinationWrapper.classList.add('d-none');
    els.productSuggestions.innerHTML = '';
    els.productSuggestions.classList.add('d-none');
}

function updateMovementTypeState(type) {
    state.selectedMovementType = type;
    updateTypeButtons(els.movementTypeBtns, type, 'data-movement-type');

    if (type === 'transfer') {
        els.destinationWrapper.classList.remove('d-none');
    } else {
        els.destinationWrapper.classList.add('d-none');
        els.destinationSelect.value = '';
    }

    if (type === 'adjustment') {
        els.quantity.placeholder = 'e.g. -2 or 5';
    } else {
        els.quantity.placeholder = 'e.g. 10';
    }
}

function renderProductSuggestions(items) {
    els.productSuggestions.innerHTML = '';

    if (items.length === 0) {
        els.productSuggestions.classList.add('d-none');
        return;
    }

    items.forEach((item) => {
        const key = `${item.sku} - ${item.name}`;
        state.productLookup.set(key, item.id);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'list-group-item list-group-item-action';
        button.textContent = key;

        button.addEventListener('click', () => {
            state.selectedProduct = item;
            els.productSearch.value = key;
            els.productId.value = String(item.id);
            els.productSuggestions.classList.add('d-none');
        });

        els.productSuggestions.appendChild(button);
    });

    els.productSuggestions.classList.remove('d-none');
}

async function searchProducts(term) {
    if (term.trim().length < 2) {
        renderProductSuggestions([]);
        return;
    }

    try {
        const params = new URLSearchParams();
        params.set('search', term.trim());
        params.set('page', '1');
        params.set('per_page', '8');

        const response = await apiRequest(`/products?${params.toString()}`, { method: 'GET' });
        renderProductSuggestions(response.data.items || []);
    } catch (error) {
        renderProductSuggestions([]);
    }
}

function collectPayload() {
    const quantity = Number(els.quantity.value);

    const payload = {
        product_id: Number(els.productId.value),
        warehouse_id: Number(els.warehouseSelect.value),
        type: state.selectedMovementType,
        quantity,
        reference: els.reference.value.trim(),
        notes: els.notes.value.trim(),
    };

    if (state.selectedMovementType === 'transfer') {
        payload.destination_warehouse_id = Number(els.destinationSelect.value);
    }

    return payload;
}

function validatePayload(payload) {
    const errors = {};

    if (!payload.product_id) {
        errors.product_id = 'Please select a product from suggestions.';
    }
    if (!payload.warehouse_id) {
        errors.warehouse_id = 'Warehouse is required.';
    }

    if (!Number.isFinite(payload.quantity)) {
        errors.quantity = 'Quantity must be numeric.';
    } else if (!Number.isInteger(payload.quantity)) {
        errors.quantity = 'Quantity must be a whole number.';
    } else if (payload.type === 'adjustment' && payload.quantity === 0) {
        errors.quantity = 'Adjustment quantity cannot be zero.';
    } else if (payload.type !== 'adjustment' && payload.quantity <= 0) {
        errors.quantity = 'Quantity must be greater than zero.';
    }

    if (payload.type === 'transfer') {
        if (!payload.destination_warehouse_id) {
            errors.destination_warehouse_id = 'Destination warehouse is required.';
        } else if (payload.destination_warehouse_id === payload.warehouse_id) {
            errors.destination_warehouse_id = 'Destination must be different from source.';
        }
    }

    return errors;
}

function bindEvents() {
    els.typeFilterBtns.forEach((button) => {
        button.addEventListener('click', async () => {
            state.typeFilter = button.getAttribute('data-type-filter') || 'all';
            updateTypeButtons(els.typeFilterBtns, state.typeFilter, 'data-type-filter');
            state.page = 1;
            await loadMovements();
        });
    });

    els.warehouseFilter.addEventListener('change', async () => {
        state.warehouseFilter = els.warehouseFilter.value;
        state.page = 1;
        await loadMovements();
    });

    els.dateFrom.addEventListener('change', async () => {
        state.dateFrom = els.dateFrom.value;
        state.page = 1;
        await loadMovements();
    });

    els.dateTo.addEventListener('change', async () => {
        state.dateTo = els.dateTo.value;
        state.page = 1;
        await loadMovements();
    });

    let searchTimer = null;
    els.movementSearch.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(async () => {
            state.search = els.movementSearch.value.trim();
            state.page = 1;
            await loadMovements();
        }, 300);
    });

    els.prevBtn.addEventListener('click', async () => {
        if (state.page > 1) {
            state.page -= 1;
            await loadMovements();
        }
    });

    els.nextBtn.addEventListener('click', async () => {
        if (state.page < state.totalPages) {
            state.page += 1;
            await loadMovements();
        }
    });

    els.openFormBtn.addEventListener('click', () => {
        resetForm();
        movementCanvas.show();
    });

    els.movementTypeBtns.forEach((button) => {
        button.addEventListener('click', () => {
            const type = button.getAttribute('data-movement-type') || 'in';
            updateMovementTypeState(type);
        });
    });

    let productSearchTimer = null;
    els.productSearch.addEventListener('input', () => {
        const typed = els.productSearch.value;
        if (state.selectedProduct && `${state.selectedProduct.sku} - ${state.selectedProduct.name}` !== typed) {
            state.selectedProduct = null;
            els.productId.value = '';
        }

        clearTimeout(productSearchTimer);
        productSearchTimer = setTimeout(() => {
            searchProducts(typed);
        }, 250);
    });

    document.addEventListener('click', (event) => {
        if (!els.productSuggestions.contains(event.target) && event.target !== els.productSearch) {
            els.productSuggestions.classList.add('d-none');
        }
    });

    els.form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors();

        const payload = collectPayload();
        const validationErrors = validatePayload(payload);
        if (Object.keys(validationErrors).length > 0) {
            setErrors(validationErrors);
            return;
        }

        try {
            await apiRequest('/stock-movements', {
                method: 'POST',
                body: JSON.stringify(payload),
            });

            showToast('Stock movement recorded.');
            movementCanvas.hide();
            resetForm();
            state.page = 1;
            await loadMovements();
        } catch (error) {
            const fieldErrors = error?.response?.data?.fields || {};
            if (Object.keys(fieldErrors).length > 0) {
                setErrors(fieldErrors);
            }

            if (error.status === 409 && error?.response?.data?.current_quantity !== undefined) {
                showToast(`Insufficient stock. Current quantity: ${error.response.data.current_quantity}`, 'danger');
            } else {
                showToast(error.message || 'Failed to record movement.', 'danger');
            }
        }
    });

    canvasEl.addEventListener('hidden.bs.offcanvas', () => {
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
    await loadMovements();
}

init();
