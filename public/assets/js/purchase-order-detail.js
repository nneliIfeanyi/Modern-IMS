import { apiRequest, getCurrentUser } from './auth.js';

const url = new URL(window.location.href);
const poIdParam = url.searchParams.get('id');
const isNew = url.searchParams.get('new') === '1' || !poIdParam;

const state = {
    poId: poIdParam ? Number(poIdParam) : null,
    status: 'draft',
    suppliers: [],
    warehouses: [],
    products: [],
    items: [],
    initialWarehouseId: null,
};

const els = {
    title: document.getElementById('po-detail-title'),
    subtitle: document.getElementById('po-detail-subtitle'),
    supplier: document.getElementById('po-supplier'),
    warehouse: document.getElementById('po-warehouse'),
    orderDate: document.getElementById('po-order-date'),
    expectedDate: document.getElementById('po-expected-date'),
    notes: document.getElementById('po-notes'),
    addItemBtn: document.getElementById('po-add-item'),
    itemsBody: document.getElementById('po-items-body'),
    totals: document.getElementById('po-totals'),
    saveDraft: document.getElementById('po-save-draft'),
    send: document.getElementById('po-send'),
    receive: document.getElementById('po-receive'),
    currentStatus: document.getElementById('po-current-status'),
    timeline: document.getElementById('po-timeline'),
    receiveBody: document.getElementById('receive-items-body'),
    receiveForm: document.getElementById('receive-form'),
    toastEl: document.getElementById('po-toast'),
    toastBody: document.getElementById('po-toast-body'),
};

const receiveModalEl = document.getElementById('receiveModal');
const receiveModal = new bootstrap.Modal(receiveModalEl);
const toast = new bootstrap.Toast(els.toastEl);

function showToast(message, tone = 'success') {
    els.toastBody.textContent = message;
    els.toastEl.className = `toast align-items-center text-bg-${tone} border-0`;
    toast.show();
}

function clearErrors() {
    document.querySelectorAll('[data-po-error]').forEach((node) => {
        node.textContent = '';
    });
    document.querySelectorAll('[data-receive-error]').forEach((node) => {
        node.textContent = '';
    });
}

function setErrors(fields = {}) {
    Object.entries(fields).forEach(([key, msg]) => {
        const node = document.querySelector(`[data-po-error="${key}"]`) || document.querySelector(`[data-receive-error="${key}"]`);
        if (node) {
            node.textContent = msg;
        }
    });
}

function formatNgn(value) {
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
        minimumFractionDigits: 2,
    }).format(Number(value || 0));
}

function buildProductOptions(selectedId = '') {
    const first = '<option value="">Select product</option>';
    const options = state.products.map((product) => {
        const selected = Number(selectedId) === Number(product.id) ? 'selected' : '';
        return `<option value="${product.id}" ${selected}>${product.sku} - ${product.name}</option>`;
    });
    return [first, ...options].join('');
}

function renderItemsTable() {
    if (state.items.length === 0) {
        els.itemsBody.innerHTML = '<tr><td colspan="6" class="text-muted">No items yet. Add at least one line.</td></tr>';
        els.totals.textContent = 'Total: NGN 0.00';
        return;
    }

    let total = 0;
    els.itemsBody.innerHTML = '';

    state.items.forEach((item, idx) => {
        const qtyOrdered = Number(item.qty_ordered || 0);
        const qtyReceived = Number(item.qty_received || 0);
        const unitCost = Number(item.unit_cost || 0);
        const subtotal = qtyOrdered * unitCost;
        total += subtotal;

        const tr = document.createElement('tr');
        tr.innerHTML = `
      <td>
        <select class="form-select form-select-sm" data-item-field="product_id" data-item-index="${idx}">
          ${buildProductOptions(item.product_id)}
        </select>
      </td>
      <td><input class="form-control form-control-sm" data-item-field="qty_ordered" data-item-index="${idx}" type="number" min="1" step="1" value="${qtyOrdered || ''}"></td>
      <td>${qtyReceived}</td>
      <td><input class="form-control form-control-sm" data-item-field="unit_cost" data-item-index="${idx}" type="number" min="0" step="0.01" value="${unitCost || ''}"></td>
      <td>${formatNgn(subtotal)}</td>
      <td class="text-end"><button class="btn btn-sm btn-outline-danger" data-remove-item="${idx}" type="button">Remove</button></td>
    `;

        els.itemsBody.appendChild(tr);
    });

    els.totals.textContent = `Total: ${formatNgn(total)}`;

    els.itemsBody.querySelectorAll('[data-item-field]').forEach((input) => {
        input.addEventListener('change', (event) => {
            const index = Number(event.target.getAttribute('data-item-index'));
            const field = event.target.getAttribute('data-item-field');
            let value = event.target.value;

            if (field === 'product_id' || field === 'qty_ordered') {
                value = Number(value);
            }
            if (field === 'unit_cost') {
                value = Number(value);
            }

            state.items[index][field] = value;
            renderItemsTable();
        });
    });

    els.itemsBody.querySelectorAll('[data-remove-item]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const idx = Number(btn.getAttribute('data-remove-item'));
            state.items.splice(idx, 1);
            renderItemsTable();
        });
    });
}

function getHeaderPayload() {
    return {
        supplier_id: Number(els.supplier.value),
        warehouse_id: Number(els.warehouse.value),
        order_date: els.orderDate.value || null,
        expected_date: els.expectedDate.value || null,
        notes: els.notes.value.trim() || null,
    };
}

function getItemsPayload() {
    return state.items.map((item) => ({
        product_id: Number(item.product_id),
        qty_ordered: Number(item.qty_ordered),
        unit_cost: Number(item.unit_cost),
    }));
}

function localValidate() {
    const fields = {};
    const header = getHeaderPayload();

    if (!header.supplier_id) {
        fields.supplier_id = 'Supplier is required.';
    }
    if (!header.warehouse_id) {
        fields.warehouse_id = 'Warehouse is required.';
    }

    if (state.items.length === 0) {
        fields.items = 'At least one item is required.';
    }

    state.items.forEach((item, idx) => {
        if (!Number(item.product_id)) {
            fields[`items.${idx}.product_id`] = 'Select a product.';
        }
        if (!Number.isInteger(Number(item.qty_ordered)) || Number(item.qty_ordered) <= 0) {
            fields[`items.${idx}.qty_ordered`] = 'Enter valid qty.';
        }
        if (!Number.isFinite(Number(item.unit_cost)) || Number(item.unit_cost) < 0) {
            fields[`items.${idx}.unit_cost`] = 'Enter valid cost.';
        }
    });

    return fields;
}

function applyOrderData(payload) {
    const order = payload.order;
    const items = payload.items || [];

    state.poId = Number(order.id);
    state.status = order.status;

    els.title.textContent = `${order.po_number} (${order.status.replace('_', ' ')})`;
    els.subtitle.textContent = `${order.supplier_name} -> ${order.warehouse_name}`;
    els.currentStatus.textContent = order.status;

    els.supplier.value = String(order.supplier_id);
    els.warehouse.value = String(order.warehouse_id);
    els.orderDate.value = order.order_date || '';
    els.expectedDate.value = order.expected_date || '';
    els.notes.value = order.notes || '';

    state.items = items.map((item) => ({
        id: Number(item.id),
        product_id: Number(item.product_id),
        qty_ordered: Number(item.qty_ordered),
        qty_received: Number(item.qty_received),
        unit_cost: Number(item.unit_cost),
        product_name: item.product_name,
        sku: item.sku,
    }));

    renderItemsTable();
    applyStatusLocks();
}

function applyStatusLocks() {
    const draft = state.status === 'draft';
    const receivable = state.status === 'ordered' || state.status === 'partially_received';

    [els.supplier, els.warehouse, els.orderDate, els.expectedDate, els.notes].forEach((el) => {
        el.disabled = !draft;
    });

    els.addItemBtn.disabled = !draft;
    els.saveDraft.disabled = !draft;
    els.send.disabled = !draft;
    els.receive.disabled = !receivable;

    const editableInputs = els.itemsBody.querySelectorAll('select, input, button[data-remove-item]');
    editableInputs.forEach((node) => {
        if (node.getAttribute('data-remove-item') !== null || node.getAttribute('data-item-field') !== null) {
            node.disabled = !draft;
        }
    });
}

function renderReceiveModal() {
    els.receiveBody.innerHTML = '';
    const receivableItems = state.items.filter((item) => Number(item.qty_received) < Number(item.qty_ordered));

    if (receivableItems.length === 0) {
        els.receiveBody.innerHTML = '<tr><td colspan="7" class="text-muted">All items fully received.</td></tr>';
        return;
    }

    receivableItems.forEach((item) => {
        const remaining = Number(item.qty_ordered) - Number(item.qty_received);
        const tr = document.createElement('tr');
        tr.innerHTML = `
      <td>${item.sku} - ${item.product_name}</td>
      <td>${item.qty_ordered}</td>
      <td>${item.qty_received}</td>
      <td>${remaining}</td>
    <td><input class="form-control form-control-sm" type="text" maxlength="80" data-receive-batch="${item.id}" aria-label="Batch number for ${item.product_name}"></td>
    <td><input class="form-control form-control-sm" type="date" data-receive-expiry="${item.id}" aria-label="Expiry date for ${item.product_name}"></td>
    <td><input class="form-control form-control-sm" type="number" min="0" max="${remaining}" step="1" data-receive-item="${item.id}" value="0"></td>
    `;
        els.receiveBody.appendChild(tr);
    });
}

async function loadLookups() {
    const [suppliersRes, warehousesRes, productsRes] = await Promise.all([
        apiRequest('/suppliers', { method: 'GET' }),
        apiRequest('/warehouses', { method: 'GET' }),
        apiRequest('/products?page=1&per_page=200', { method: 'GET' }),
    ]);

    state.suppliers = suppliersRes.data || [];
    state.warehouses = warehousesRes.data || [];
    state.products = productsRes.data?.items || [];
    state.initialWarehouseId = state.warehouses[0]?.id ? Number(state.warehouses[0].id) : null;

    els.supplier.innerHTML = '<option value="">Select supplier</option>';
    state.suppliers.forEach((supplier) => {
        const opt = document.createElement('option');
        opt.value = String(supplier.id);
        opt.textContent = supplier.name;
        els.supplier.appendChild(opt);
    });

    els.warehouse.innerHTML = '<option value="">Select warehouse</option>';
    state.warehouses.forEach((warehouse) => {
        const opt = document.createElement('option');
        opt.value = String(warehouse.id);
        opt.textContent = warehouse.name;
        els.warehouse.appendChild(opt);
    });

    if (!state.poId && state.initialWarehouseId) {
        els.warehouse.value = String(state.initialWarehouseId);
    }
}

async function loadExistingOrder() {
    if (!state.poId) {
        state.status = 'draft';
        applyStatusLocks();
        return;
    }

    const response = await apiRequest(`/purchase-orders/${state.poId}`, { method: 'GET' });
    applyOrderData(response.data);
}

async function saveDraft() {
    clearErrors();
    const fields = localValidate();
    if (Object.keys(fields).length > 0) {
        setErrors(fields);
        return;
    }

    const payload = {
        ...getHeaderPayload(),
        items: getItemsPayload(),
    };

    try {
        let response;
        if (state.poId) {
            response = await apiRequest(`/purchase-orders/${state.poId}`, {
                method: 'PUT',
                body: JSON.stringify(payload),
            });
            showToast('Draft updated.');
        } else {
            response = await apiRequest('/purchase-orders', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            showToast('Draft created.');
        }

        applyOrderData(response.data);
        window.history.replaceState({}, '', `purchase-order-detail.html?id=${state.poId}`);
    } catch (error) {
        setErrors(error?.response?.data?.fields || {});
        showToast(error.message || 'Failed to save draft.', 'danger');
    }
}

async function sendOrder() {
    if (!state.poId) {
        showToast('Save draft before sending.', 'danger');
        return;
    }

    try {
        const response = await apiRequest(`/purchase-orders/${state.poId}/send`, {
            method: 'POST',
            body: JSON.stringify({}),
        });
        applyOrderData(response.data);
        showToast('Purchase order sent.');
    } catch (error) {
        showToast(error.message || 'Failed to send purchase order.', 'danger');
    }
}

async function submitReceipt(event) {
    event.preventDefault();
    clearErrors();

    const inputs = els.receiveBody.querySelectorAll('[data-receive-item]');
    const receipts = [];

    inputs.forEach((input) => {
        const qty = Number(input.value || 0);
        if (qty > 0) {
            const itemId = Number(input.getAttribute('data-receive-item'));
            const batchInput = els.receiveBody.querySelector(`[data-receive-batch="${itemId}"]`);
            const expiryInput = els.receiveBody.querySelector(`[data-receive-expiry="${itemId}"]`);
            receipts.push({
                item_id: itemId,
                quantity: qty,
                batch_number: batchInput?.value.trim() || '',
                expiry_date: expiryInput?.value || '',
            });
        }
    });

    if (receipts.length === 0) {
        setErrors({ receipts: 'Enter at least one receipt quantity greater than zero.' });
        return;
    }

    try {
        const response = await apiRequest(`/purchase-orders/${state.poId}/receive`, {
            method: 'POST',
            body: JSON.stringify({ receipts }),
        });

        applyOrderData(response.data);
        receiveModal.hide();
        showToast('Stock received and purchase order updated.');
    } catch (error) {
        setErrors(error?.response?.data?.fields || {});
        showToast(error.message || 'Failed to receive stock.', 'danger');
    }
}

function bindEvents() {
    els.addItemBtn.addEventListener('click', () => {
        state.items.push({
            id: null,
            product_id: '',
            qty_ordered: 1,
            qty_received: 0,
            unit_cost: 0,
        });
        renderItemsTable();
        applyStatusLocks();
    });

    els.saveDraft.addEventListener('click', saveDraft);
    els.send.addEventListener('click', sendOrder);

    els.receive.addEventListener('click', () => {
        if (!state.poId) {
            showToast('Save draft before receiving stock.', 'danger');
            return;
        }

        renderReceiveModal();
        receiveModal.show();
    });

    els.receiveForm.addEventListener('submit', submitReceipt);
}

async function init() {
    try {
        await getCurrentUser();
    } catch (error) {
        window.location.href = 'login.html';
        return;
    }

    if (isNew) {
        state.poId = null;
    }

    bindEvents();
    await loadLookups();

    if (state.poId) {
        await loadExistingOrder();
    } else {
        state.items = [{
            id: null,
            product_id: '',
            qty_ordered: 1,
            qty_received: 0,
            unit_cost: 0,
        }];
        renderItemsTable();
        applyStatusLocks();
    }
}

init();
