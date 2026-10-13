import { apiRequest, getCurrentUser } from './auth.js';

const url = new URL(window.location.href);
const soIdParam = url.searchParams.get('id');

function resolveApiBase() {
    const path = window.location.pathname || '/';
    const lastSlash = path.lastIndexOf('/');
    const dir = lastSlash >= 0 ? path.slice(0, lastSlash) : '';

    if (dir === '' || dir === '/') {
        return '/api';
    }

    return `${dir}/api`;
}

const API_BASE = resolveApiBase();

const state = {
    soId: soIdParam ? Number(soIdParam) : null,
    status: 'draft',
    warehouses: [],
    products: [],
    customers: [],
    items: [],
    initialWarehouseId: null,
    receipt: null,
    customerAccount: null,
};

const els = {
    title: document.getElementById('so-detail-title'),
    subtitle: document.getElementById('so-detail-subtitle'),
    customerSelect: document.getElementById('so-customer-select'),
    customerName: document.getElementById('so-customer-name'),
    paymentMode: document.getElementById('so-payment-mode'),
    initialPayment: document.getElementById('so-initial-payment'),
    initialPaymentHelp: document.getElementById('so-initial-payment-help'),
    warehouse: document.getElementById('so-warehouse'),
    orderDate: document.getElementById('so-order-date'),
    notes: document.getElementById('so-notes'),
    addItemBtn: document.getElementById('so-add-item'),
    itemsBody: document.getElementById('so-items-body'),
    totals: document.getElementById('so-totals'),
    receiptTotal: document.getElementById('so-receipt-total'),
    receiptPaid: document.getElementById('so-receipt-paid'),
    receiptBalance: document.getElementById('so-receipt-balance'),
    customerBalance: document.getElementById('so-customer-balance'),
    customerCreditLimit: document.getElementById('so-customer-credit-limit'),
    customerSummaryNote: document.getElementById('so-customer-summary-note'),
    saveDraft: document.getElementById('so-save-draft'),
    confirm: document.getElementById('so-confirm'),
    fulfill: document.getElementById('so-fulfill'),
    printReceipt: document.getElementById('so-print-receipt'),
    downloadReceipt: document.getElementById('so-download-receipt'),
    currentStatus: document.getElementById('so-current-status'),
    fulfillBody: document.getElementById('fulfill-items-body'),
    fulfillForm: document.getElementById('fulfill-form'),
    toastEl: document.getElementById('so-toast'),
    toastBody: document.getElementById('so-toast-body'),
};

const fulfillModalEl = document.getElementById('fulfillModal');
const fulfillModal = new bootstrap.Modal(fulfillModalEl);
const toast = new bootstrap.Toast(els.toastEl);

function showToast(message, tone = 'success') {
    els.toastBody.textContent = message;
    els.toastEl.className = `toast align-items-center text-bg-${tone} border-0`;
    toast.show();
}

function clearErrors() {
    document.querySelectorAll('[data-so-error]').forEach((node) => {
        node.textContent = '';
    });
    document.querySelectorAll('[data-fulfill-error]').forEach((node) => {
        node.textContent = '';
    });
}

function setErrors(fields = {}) {
    Object.entries(fields).forEach(([key, msg]) => {
        const node = document.querySelector(`[data-so-error="${key}"]`) || document.querySelector(`[data-fulfill-error="${key}"]`);
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

function buildReceiptUrl(format = 'json') {
    return `${API_BASE}/sales-orders/${state.soId}/receipt?format=${encodeURIComponent(format)}`;
}

function buildProductOptions(selectedId = '') {
    const first = '<option value="">Select product</option>';
    const options = state.products.map((product) => {
        const selected = Number(selectedId) === Number(product.id) ? 'selected' : '';
        return `<option value="${product.id}" ${selected}>${product.sku} - ${product.name}</option>`;
    });
    return [first, ...options].join('');
}

function buildCustomerOptions(selectedId = '') {
    const first = '<option value="">Walk-in customer</option>';
    const options = state.customers.map((customer) => {
        const selected = Number(selectedId) === Number(customer.id) ? 'selected' : '';
        return `<option value="${customer.id}" ${selected}>${customer.customer_code} - ${customer.full_name}</option>`;
    });
    return [first, ...options].join('');
}

function getSelectedCustomer() {
    const customerId = Number(els.customerSelect.value || 0);
    if (!customerId) {
        return null;
    }

    return state.customers.find((customer) => Number(customer.id) === customerId) || null;
}

function syncCustomerFields() {
    const customer = getSelectedCustomer();
    if (customer) {
        els.customerName.value = customer.full_name || '';
    }
}

function calculateItemsTotal() {
    return state.items.reduce((total, item) => total + (Number(item.qty_ordered || 0) * Number(item.unit_price || 0)), 0);
}

function syncInitialPayment(total = calculateItemsTotal()) {
    if (els.paymentMode.value === 'cash') {
        els.initialPayment.value = total.toFixed(2);
        els.initialPaymentHelp.textContent = 'Cash sales require the full order total.';
    } else if (els.paymentMode.value === 'credit') {
        els.initialPayment.value = '0.00';
        els.initialPaymentHelp.textContent = 'The full order total will remain on the customer account.';
    } else {
        els.initialPaymentHelp.textContent = `Outstanding credit: ${formatNgn(Math.max(0, total - Number(els.initialPayment.value || 0)))}.`;
    }
}

function updateReceiptSummary(summary = {}) {
    const total = Number(summary.total_amount || 0);
    const paid = Number(summary.total_paid || 0);
    const balance = Number(summary.balance_due ?? Math.max(0, total - paid));

    els.receiptTotal.textContent = `Total: ${formatNgn(total)}`;
    els.receiptPaid.textContent = `Paid: ${formatNgn(paid)}`;
    els.receiptBalance.textContent = `Balance: ${formatNgn(balance)}`;
}

function updateCustomerAccountSummary(customer) {
    if (!customer) {
        state.customerAccount = null;
        els.customerBalance.textContent = formatNgn(0);
        els.customerCreditLimit.textContent = formatNgn(0);
        els.customerSummaryNote.textContent = 'Walk-in customers do not carry a credit book.';
        return;
    }

    state.customerAccount = customer;
    const summary = customer.summary || {};
    els.customerBalance.textContent = formatNgn(summary.balance ?? customer.balance ?? 0);
    els.customerCreditLimit.textContent = formatNgn(customer.credit_limit || 0);
    els.customerSummaryNote.textContent = 'Customer ledger totals reflect recorded payments across the account.';
}

function renderItemsTable() {
    if (state.items.length === 0) {
        els.itemsBody.innerHTML = '<tr><td colspan="6" class="text-muted">No items yet. Add at least one line.</td></tr>';
        els.totals.textContent = 'Total: NGN 0.00';
        syncInitialPayment(0);
        return;
    }

    let total = 0;
    els.itemsBody.innerHTML = '';

    state.items.forEach((item, idx) => {
        const qtyOrdered = Number(item.qty_ordered || 0);
        const qtyFulfilled = Number(item.qty_fulfilled || 0);
        const unitPrice = Number(item.unit_price || 0);
        const subtotal = qtyOrdered * unitPrice;
        total += subtotal;

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <select class="form-select form-select-sm" data-item-field="product_id" data-item-index="${idx}">
                    ${buildProductOptions(item.product_id)}
                </select>
            </td>
            <td><input class="form-control form-control-sm" data-item-field="qty_ordered" data-item-index="${idx}" type="number" min="1" step="1" value="${qtyOrdered || ''}"></td>
            <td>${qtyFulfilled}</td>
            <td><input class="form-control form-control-sm" data-item-field="unit_price" data-item-index="${idx}" type="number" min="0" step="0.01" value="${unitPrice || ''}"></td>
            <td>${formatNgn(subtotal)}</td>
            <td class="text-end"><button class="btn btn-sm btn-outline-danger" data-remove-item="${idx}" type="button">Remove</button></td>
        `;

        els.itemsBody.appendChild(tr);
    });

    els.totals.textContent = `Total: ${formatNgn(total)}`;
    syncInitialPayment(total);

    els.itemsBody.querySelectorAll('[data-item-field]').forEach((input) => {
        input.addEventListener('change', (event) => {
            const index = Number(event.target.getAttribute('data-item-index'));
            const field = event.target.getAttribute('data-item-field');
            let value = event.target.value;

            if (field === 'product_id' || field === 'qty_ordered') {
                value = Number(value);
            }
            if (field === 'unit_price') {
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
        customer_id: Number(els.customerSelect.value || 0) || null,
        customer_name: els.customerName.value.trim() || null,
        warehouse_id: Number(els.warehouse.value),
        payment_mode: els.paymentMode.value,
        initial_payment: Number(els.initialPayment.value || 0),
        order_date: els.orderDate.value || null,
        notes: els.notes.value.trim() || null,
    };
}

function getItemsPayload() {
    return state.items.map((item) => ({
        product_id: Number(item.product_id),
        qty_ordered: Number(item.qty_ordered),
        unit_price: Number(item.unit_price),
    }));
}

function localValidate() {
    const fields = {};
    const header = getHeaderPayload();

    if (!header.warehouse_id) {
        fields.warehouse_id = 'Warehouse is required.';
    }

    if (!header.payment_mode) {
        fields.payment_mode = 'Payment mode is required.';
    }

    const creditSale = header.payment_mode === 'credit' || header.payment_mode === 'mixed';
    if (creditSale && !header.customer_id && !header.customer_name) {
        fields.customer_name = 'Customer name is required for credit or mixed sales.';
    }

    if (state.items.length === 0) {
        fields.items = 'At least one item is required.';
    }

    const total = calculateItemsTotal();
    if (!Number.isFinite(header.initial_payment) || header.initial_payment < 0) {
        fields.initial_payment = 'Enter a valid non-negative payment.';
    } else if (header.initial_payment > total + 0.009) {
        fields.initial_payment = 'Initial payment cannot exceed the order total.';
    } else if (header.payment_mode === 'cash' && Math.abs(header.initial_payment - total) > 0.009) {
        fields.initial_payment = 'Cash sales require payment of the full order total.';
    } else if (header.payment_mode === 'credit' && header.initial_payment > 0.009) {
        fields.initial_payment = 'Credit sales must have zero initial payment.';
    } else if (header.payment_mode === 'mixed' && (header.initial_payment <= 0.009 || header.initial_payment >= total - 0.009)) {
        fields.initial_payment = 'Mixed sales require a payment less than the order total.';
    }

    state.items.forEach((item, idx) => {
        if (!Number(item.product_id)) {
            fields[`items.${idx}.product_id`] = 'Select a product.';
        }
        if (!Number.isInteger(Number(item.qty_ordered)) || Number(item.qty_ordered) <= 0) {
            fields[`items.${idx}.qty_ordered`] = 'Enter valid qty.';
        }
        if (!Number.isFinite(Number(item.unit_price)) || Number(item.unit_price) < 0) {
            fields[`items.${idx}.unit_price`] = 'Enter valid price.';
        }
    });

    return fields;
}

function applyOrderData(payload) {
    const order = payload.order;
    const items = payload.items || [];

    state.soId = Number(order.id);
    state.status = order.status;

    els.title.textContent = `${order.so_number} (${order.status.replace('_', ' ')})`;
    els.subtitle.textContent = `${order.customer_display_name || order.customer_name || 'Walk-in customer'} -> ${order.warehouse_name}`;
    els.currentStatus.textContent = order.status;

    els.customerSelect.innerHTML = buildCustomerOptions(order.customer_id || '');
    els.customerName.value = order.customer_name || '';
    els.paymentMode.value = order.payment_mode || 'cash';
    els.initialPayment.value = Number(order.initial_payment || 0).toFixed(2);
    els.warehouse.value = String(order.warehouse_id);
    els.orderDate.value = order.order_date || '';
    els.notes.value = order.notes || '';

    state.items = items.map((item) => ({
        id: Number(item.id),
        product_id: Number(item.product_id),
        qty_ordered: Number(item.qty_ordered),
        qty_fulfilled: Number(item.qty_fulfilled),
        unit_price: Number(item.unit_price),
        product_name: item.product_name,
        sku: item.sku,
    }));

    renderItemsTable();
    updateReceiptSummary(payload.summary || {});
    applyStatusLocks();
}

function applyStatusLocks() {
    const draft = state.status === 'draft';
    const fulfillable = state.status === 'confirmed' || state.status === 'partially_fulfilled';

    [els.customerSelect, els.customerName, els.paymentMode, els.initialPayment, els.warehouse, els.orderDate, els.notes].forEach((el) => {
        el.disabled = !draft;
    });

    els.addItemBtn.disabled = !draft;
    els.saveDraft.disabled = !draft;
    els.confirm.disabled = !draft;
    els.fulfill.disabled = !fulfillable;

    const editableInputs = els.itemsBody.querySelectorAll('select, input, button[data-remove-item]');
    editableInputs.forEach((node) => {
        if (node.getAttribute('data-remove-item') !== null || node.getAttribute('data-item-field') !== null) {
            node.disabled = !draft;
        }
    });
}

function renderFulfillModal() {
    els.fulfillBody.innerHTML = '';
    const fulfillableItems = state.items.filter((item) => Number(item.qty_fulfilled) < Number(item.qty_ordered));

    if (fulfillableItems.length === 0) {
        els.fulfillBody.innerHTML = '<tr><td colspan="5" class="text-muted">All items fully fulfilled.</td></tr>';
        return;
    }

    fulfillableItems.forEach((item) => {
        const remaining = Number(item.qty_ordered) - Number(item.qty_fulfilled);
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${item.sku} - ${item.product_name}</td>
            <td>${item.qty_ordered}</td>
            <td>${item.qty_fulfilled}</td>
            <td>${remaining}</td>
            <td><input class="form-control form-control-sm" type="number" min="0" max="${remaining}" step="1" data-fulfill-item="${item.id}" value="0"></td>
        `;
        els.fulfillBody.appendChild(tr);
    });
}

async function loadLookups() {
    const [warehousesRes, productsRes, customersRes] = await Promise.all([
        apiRequest('/warehouses', { method: 'GET' }),
        apiRequest('/products?page=1&per_page=200', { method: 'GET' }),
        apiRequest('/customers', { method: 'GET' }),
    ]);

    state.warehouses = warehousesRes.data || [];
    state.products = productsRes.data?.items || [];
    state.customers = customersRes.data || [];
    state.initialWarehouseId = state.warehouses[0]?.id ? Number(state.warehouses[0].id) : null;

    els.warehouse.innerHTML = '<option value="">Select warehouse</option>';
    state.warehouses.forEach((warehouse) => {
        const opt = document.createElement('option');
        opt.value = String(warehouse.id);
        opt.textContent = warehouse.name;
        els.warehouse.appendChild(opt);
    });

    if (!state.soId && state.initialWarehouseId) {
        els.warehouse.value = String(state.initialWarehouseId);
    }

    els.customerSelect.innerHTML = buildCustomerOptions();
}

async function loadExistingOrder() {
    if (!state.soId) {
        state.status = 'draft';
        applyStatusLocks();
        return;
    }

    const response = await apiRequest(`/sales-orders/${state.soId}`, { method: 'GET' });
    applyOrderData(response.data);
    await loadCustomerAccount(response.data.order.customer_id || 0);
}

async function loadCustomerAccount(customerId) {
    if (!customerId) {
        updateCustomerAccountSummary(null);
        return;
    }

    try {
        const response = await apiRequest(`/customers/${customerId}`, { method: 'GET' });
        updateCustomerAccountSummary(response.data.customer);
    } catch (error) {
        updateCustomerAccountSummary(null);
    }
}

async function loadReceipt() {
    if (!state.soId) {
        state.receipt = null;
        updateReceiptSummary({ total_amount: 0, total_paid: 0, balance_due: 0 });
        return;
    }

    const response = await apiRequest(`/sales-orders/${state.soId}/receipt`, { method: 'GET' });
    state.receipt = response.data;
    updateReceiptSummary(response.data?.summary || {});
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
        if (state.soId) {
            response = await apiRequest(`/sales-orders/${state.soId}`, {
                method: 'PUT',
                body: JSON.stringify(payload),
            });
            showToast('Draft updated.');
        } else {
            response = await apiRequest('/sales-orders', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            showToast('Draft created.');
        }

        applyOrderData(response.data);
        await loadCustomerAccount(response.data.order.customer_id || 0);
        await loadReceipt();
        window.history.replaceState({}, '', `sales-order-detail.html?id=${state.soId}`);
    } catch (error) {
        setErrors(error?.response?.data?.fields || {});
        showToast(error.message || 'Failed to save draft.', 'danger');
    }
}

async function confirmOrder() {
    if (!state.soId) {
        showToast('Save draft before confirming.', 'danger');
        return;
    }

    try {
        const response = await apiRequest(`/sales-orders/${state.soId}/confirm`, {
            method: 'POST',
            body: JSON.stringify({}),
        });
        applyOrderData(response.data);
        await loadCustomerAccount(response.data.order.customer_id || 0);
        await loadReceipt();
        showToast('Sales order confirmed.');
    } catch (error) {
        showToast(error.message || 'Failed to confirm sales order.', 'danger');
    }
}

async function submitFulfillment(event) {
    event.preventDefault();
    clearErrors();

    const inputs = els.fulfillBody.querySelectorAll('[data-fulfill-item]');
    const fulfillments = [];

    inputs.forEach((input) => {
        const qty = Number(input.value || 0);
        if (qty > 0) {
            fulfillments.push({
                item_id: Number(input.getAttribute('data-fulfill-item')),
                quantity: qty,
            });
        }
    });

    if (fulfillments.length === 0) {
        setErrors({ fulfillments: 'Enter at least one fulfillment quantity greater than zero.' });
        return;
    }

    try {
        const response = await apiRequest(`/sales-orders/${state.soId}/fulfill`, {
            method: 'POST',
            body: JSON.stringify({ fulfillments }),
        });

        applyOrderData(response.data);
        await loadCustomerAccount(response.data.order.customer_id || 0);
        await loadReceipt();
        fulfillModal.hide();
        showToast('Sales fulfillment saved.');
    } catch (error) {
        setErrors(error?.response?.data?.fields || {});
        showToast(error.message || 'Failed to fulfill stock.', 'danger');
    }
}

function renderReceiptHtml(receipt) {
    const appName = document.documentElement.dataset.appName || 'ANTOBELL IMS';
    const order = receipt?.order || {};
    const items = receipt?.items || [];
    const payments = receipt?.payments || [];
    const summary = receipt?.summary || {};

    const itemRows = items.length === 0
        ? '<tr><td colspan="4">No items</td></tr>'
        : items.map((item) => `
            <tr>
                <td>${item.product_name || ''}</td>
                <td>${item.qty_ordered || 0}</td>
                <td>${formatNgn(item.unit_price || 0)}</td>
                <td>${formatNgn(item.subtotal || 0)}</td>
            </tr>
        `).join('');

    const paymentRows = payments.length === 0
        ? '<tr><td colspan="3">No payments recorded</td></tr>'
        : payments.map((payment) => `
            <tr>
                <td>${payment.paid_at || ''}</td>
                <td>${payment.method || ''}</td>
                <td>${formatNgn(payment.amount || 0)}</td>
            </tr>
        `).join('');

    return `
        <html>
        <head>
            <title>${order.so_number || 'Sales Receipt'}</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 24px; color: #111; }
                h1, h2 { margin: 0 0 12px; }
                table { width: 100%; border-collapse: collapse; margin-top: 12px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                .summary { margin-top: 16px; }
                .summary p { margin: 4px 0; }
            </style>
        </head>
        <body>
            <h1>${appName} SALES RECEIPT</h1>
            <p><strong>Order:</strong> ${order.so_number || '-'}</p>
            <p><strong>Customer:</strong> ${order.customer_display_name || order.customer_name || 'Walk-in customer'}</p>
            <p><strong>Warehouse:</strong> ${order.warehouse_name || '-'}</p>
            <p><strong>Status:</strong> ${order.status || '-'}</p>

            <h2>Items</h2>
            <table>
                <thead>
                    <tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th></tr>
                </thead>
                <tbody>${itemRows}</tbody>
            </table>

            <div class="summary">
                <p><strong>Total:</strong> ${formatNgn(summary.total_amount || 0)}</p>
                <p><strong>Paid:</strong> ${formatNgn(summary.total_paid || 0)}</p>
                <p><strong>Balance:</strong> ${formatNgn(summary.balance_due || 0)}</p>
            </div>

            <h2>Payments</h2>
            <table>
                <thead>
                    <tr><th>Date</th><th>Method</th><th>Amount</th></tr>
                </thead>
                <tbody>${paymentRows}</tbody>
            </table>
        </body>
        </html>
    `;
}

async function printReceipt() {
    if (!state.soId) {
        showToast('Save the order first.', 'danger');
        return;
    }

    if (!state.receipt) {
        await loadReceipt();
    }

    const receiptWindow = window.open('', '_blank', 'width=900,height=700');
    if (!receiptWindow) {
        showToast('Popup blocked. Allow popups to print the receipt.', 'danger');
        return;
    }

    receiptWindow.document.open();
    receiptWindow.document.write(renderReceiptHtml(state.receipt));
    receiptWindow.document.close();
    receiptWindow.focus();
    receiptWindow.print();
}

async function downloadReceiptPdf() {
    if (!state.soId) {
        showToast('Save the order first.', 'danger');
        return;
    }

    const response = await fetch(buildReceiptUrl('pdf'), {
        method: 'GET',
        credentials: 'include',
    });

    if (!response.ok) {
        let message = 'Failed to download receipt PDF.';
        try {
            const payload = await response.json();
            message = payload.message || message;
        } catch (error) {
            message = response.statusText || message;
        }
        throw new Error(message);
    }

    const blob = await response.blob();
    const downloadUrl = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = downloadUrl;
    link.download = `${(state.receipt?.order?.so_number || `sales-order-${state.soId}`)}-receipt.pdf`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(downloadUrl);
}

function bindEvents() {
    els.customerSelect.addEventListener('change', syncCustomerFields);
    els.paymentMode.addEventListener('change', () => {
        syncInitialPayment();
    });
    els.initialPayment.addEventListener('input', () => {
        if (els.paymentMode.value === 'mixed') {
            syncInitialPayment();
        }
    });
    els.customerName.addEventListener('input', () => {
        if (Number(els.customerSelect.value || 0) > 0) {
            els.customerSelect.value = '';
        }
    });

    els.addItemBtn.addEventListener('click', () => {
        state.items.push({
            id: null,
            product_id: '',
            qty_ordered: 1,
            qty_fulfilled: 0,
            unit_price: 0,
        });
        renderItemsTable();
        applyStatusLocks();
    });

    els.saveDraft.addEventListener('click', saveDraft);
    els.confirm.addEventListener('click', confirmOrder);
    els.printReceipt.addEventListener('click', () => {
        printReceipt().catch((error) => showToast(error.message || 'Failed to print receipt.', 'danger'));
    });
    els.downloadReceipt.addEventListener('click', () => {
        downloadReceiptPdf().catch((error) => showToast(error.message || 'Failed to download receipt PDF.', 'danger'));
    });

    els.fulfill.addEventListener('click', () => {
        if (!state.soId) {
            showToast('Save draft before fulfillment.', 'danger');
            return;
        }
        renderFulfillModal();
        fulfillModal.show();
    });

    els.fulfillForm.addEventListener('submit', submitFulfillment);
}

async function init() {
    try {
        await getCurrentUser();
    } catch (error) {
        window.location.href = 'login.html';
        return;
    }

    bindEvents();

    try {
        await loadLookups();
        await loadExistingOrder();
        await loadReceipt();
    } catch (error) {
        showToast(error.message || 'Failed to load sales order.', 'danger');
    }
}

init();
