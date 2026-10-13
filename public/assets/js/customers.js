import { apiRequest, getCurrentUser } from './auth.js';
import { confirmAction } from './ui.js';

const state = {
    customers: [],
    selectedCustomerId: null,
    selectedCustomer: null,
    statement: [],
    lastPayment: null,
};

const els = {
    newCustomerBtn: document.getElementById('new-customer-btn'),
    newCustomerModal: document.getElementById('newCustomerModal'),
    customerWorkspaceModal: document.getElementById('customerWorkspaceModal'),
    customerWorkspaceTitle: document.getElementById('customerWorkspaceModalTitle'),
    editCustomerModal: document.getElementById('editCustomerModal'),
    customerWorkspaceBody: document.querySelector('#customer-workspace-body .row'),
    summaryColumn: document.getElementById('customer-summary-column'),
    statementColumn: document.getElementById('customer-statement-column'),
    createForm: document.getElementById('customer-create-form'),
    createName: document.getElementById('new-customer-name'),
    createPhone: document.getElementById('new-customer-phone'),
    createEmail: document.getElementById('new-customer-email'),
    createAddress: document.getElementById('new-customer-address'),
    createCreditLimit: document.getElementById('new-customer-credit-limit'),
    createOpeningBalance: document.getElementById('new-customer-opening-balance'),
    search: document.getElementById('customers-search'),
    searchBtn: document.getElementById('customers-search-btn'),
    refreshBtn: document.getElementById('customers-refresh'),
    listBody: document.getElementById('customers-list-body'),
    summaryEmpty: document.getElementById('customer-summary-empty'),
    summaryPanel: document.getElementById('customer-summary-panel'),
    name: document.getElementById('customer-name'),
    code: document.getElementById('customer-code'),
    phone: document.getElementById('customer-phone'),
    email: document.getElementById('customer-email'),
    creditLimit: document.getElementById('customer-credit-limit'),
    openingBalance: document.getElementById('customer-opening-balance'),
    balance: document.getElementById('customer-balance'),
    paymentForm: document.getElementById('customer-payment-form'),
    amount: document.getElementById('payment-amount'),
    method: document.getElementById('payment-method'),
    reference: document.getElementById('payment-reference'),
    notes: document.getElementById('payment-notes'),
    paidAt: document.getElementById('payment-date'),
    submit: document.getElementById('payment-submit'),
    editForm: document.getElementById('customer-edit-form'),
    editName: document.getElementById('edit-customer-name'),
    editPhone: document.getElementById('edit-customer-phone'),
    editEmail: document.getElementById('edit-customer-email'),
    editAddress: document.getElementById('edit-customer-address'),
    editCreditLimit: document.getElementById('edit-customer-credit-limit'),
    editOpeningBalance: document.getElementById('edit-customer-opening-balance'),
    editSubmit: document.getElementById('customer-edit-submit'),
    statementBody: document.getElementById('customer-statement-body'),
    printStatement: document.getElementById('customer-print-statement'),
    printReceipt: document.getElementById('customer-print-receipt'),
    toastEl: document.getElementById('customers-toast'),
    toastBody: document.getElementById('customers-toast-body'),
};

const toast = new bootstrap.Toast(els.toastEl);
const newCustomerModal = new bootstrap.Modal(els.newCustomerModal);
const customerWorkspaceModal = new bootstrap.Modal(els.customerWorkspaceModal);
const editCustomerModal = new bootstrap.Modal(els.editCustomerModal);

function formatNgn(value) {
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
        minimumFractionDigits: 2,
    }).format(Number(value || 0));
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#39;',
        '"': '&quot;',
    }[character]));
}

function printHtml(title, body) {
    const printWindow = window.open('', '_blank', 'width=900,height=700');
    if (!printWindow) {
        showToast('Popup blocked. Allow popups to print.', 'danger');
        return;
    }

    printWindow.document.open();
    printWindow.document.write(`
        <html>
        <head><title>${escapeHtml(title)}</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 24px; color: #111; }
                h1, h2 { margin: 0 0 12px; }
                table { width: 100%; border-collapse: collapse; margin-top: 16px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                .amount { text-align: right; }
            </style>
        </head>
        <body>${body}</body>
        </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
}

function printCustomerStatement() {
    if (!state.selectedCustomer) {
        return;
    }

    const customer = state.selectedCustomer;
    const appName = document.documentElement.dataset.appName || 'ANTOBELL IMS';
    const rows = state.statement.length === 0
        ? '<tr><td colspan="3">No ledger entries</td></tr>'
        : state.statement.map((entry) => `
            <tr>
                <td>${escapeHtml(entry.created_at || entry.created_on || '')}</td>
                <td>${escapeHtml(entry.entry_type || '')}</td>
                <td class="amount">${escapeHtml(formatNgn(entry.amount || 0))}</td>
            </tr>
        `).join('');

    printHtml(`${customer.full_name || 'Customer'} statement`, `
        <h1>${escapeHtml(appName)} CUSTOMER STATEMENT</h1>
        <p><strong>Customer:</strong> ${escapeHtml(customer.full_name || '-')}</p>
        <p><strong>Customer code:</strong> ${escapeHtml(customer.customer_code || '-')}</p>
        <p><strong>Current balance:</strong> ${escapeHtml(formatNgn(customer.balance || 0))}</p>
        <table>
            <thead><tr><th>Date</th><th>Type</th><th class="amount">Amount</th></tr></thead>
            <tbody>${rows}</tbody>
        </table>
    `);
}

function printLastPaymentReceipt() {
    if (!state.selectedCustomer || !state.lastPayment) {
        return;
    }

    const customer = state.selectedCustomer;
    const payment = state.lastPayment;
    const appName = document.documentElement.dataset.appName || 'ANTOBELL IMS';
    printHtml(`Payment receipt ${payment.id}`, `
        <h1>${escapeHtml(appName)} PAYMENT RECEIPT</h1>
        <p><strong>Receipt:</strong> ${escapeHtml(String(payment.id))}</p>
        <p><strong>Customer:</strong> ${escapeHtml(customer.full_name || '-')}</p>
        <p><strong>Customer code:</strong> ${escapeHtml(customer.customer_code || '-')}</p>
        <p><strong>Date:</strong> ${escapeHtml(payment.paid_at || '-')}</p>
        <p><strong>Method:</strong> ${escapeHtml(payment.method || '-')}</p>
        <p><strong>Reference:</strong> ${escapeHtml(payment.reference || '-')}</p>
        <h2>Amount paid: ${escapeHtml(formatNgn(payment.amount || 0))}</h2>
        <p>${escapeHtml(payment.notes || '')}</p>
    `);
}

function showToast(message, tone = 'success') {
    els.toastBody.textContent = message;
    els.toastEl.className = `toast align-items-center text-bg-${tone} border-0`;
    toast.show();
}

function clearPaymentErrors() {
    document.querySelectorAll('[data-payment-error]').forEach((node) => {
        node.textContent = '';
    });
}

function clearCreateErrors() {
    document.querySelectorAll('[data-create-error]').forEach((node) => {
        node.textContent = '';
    });
}

function clearEditErrors() {
    document.querySelectorAll('[data-edit-error]').forEach((node) => {
        node.textContent = '';
    });
}

function setCreateErrors(fields = {}) {
    Object.entries(fields).forEach(([key, message]) => {
        const node = document.querySelector(`[data-create-error="${key}"]`);
        if (node) {
            node.textContent = message;
        }
    });
}

function setEditErrors(fields = {}) {
    Object.entries(fields).forEach(([key, message]) => {
        const node = document.querySelector(`[data-edit-error="${key}"]`);
        if (node) {
            node.textContent = message;
        }
    });
}

function setPaymentErrors(fields = {}) {
    Object.entries(fields).forEach(([key, message]) => {
        const node = document.querySelector(`[data-payment-error="${key}"]`);
        if (node) {
            node.textContent = message;
        }
    });
}

function renderCustomerRows() {
    if (state.customers.length === 0) {
        els.listBody.innerHTML = '<tr><td colspan="5" class="text-muted">No customers found.</td></tr>';
        return;
    }

    els.listBody.innerHTML = state.customers.map((customer) => {
        const activeClass = Number(customer.id) === Number(state.selectedCustomerId) ? 'table-primary' : '';
        return `
            <tr class="${activeClass}">
                <td>
                    <div class="fw-semibold">${customer.full_name}</div>
                    <div class="small text-muted">${customer.customer_code || ''}</div>
                </td>
                <td>${customer.phone || '-'}</td>
                <td>${customer.email || '-'}</td>
                <td class="text-end fw-semibold ${Number(customer.balance || 0) > 0 ? 'text-danger' : 'text-success'}">
                    ${formatNgn(customer.balance || 0)}
                </td>
                <td class="text-end">
                    <div class="d-inline-flex gap-1">
                        <button class="btn btn-sm btn-outline-primary" type="button" data-customer-action="view"
                            data-customer-id="${customer.id}" title="View customer" aria-label="View customer">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-customer-action="edit"
                            data-customer-id="${customer.id}" title="Record payment" aria-label="Record payment">
                            <i class="bi bi-pencil" aria-hidden="true"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" type="button" data-customer-action="deactivate"
                            data-customer-id="${customer.id}" title="Deactivate customer" aria-label="Deactivate customer">
                            <i class="bi bi-person-dash" aria-hidden="true"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');

    els.listBody.querySelectorAll('[data-customer-action]').forEach((button) => {
        button.addEventListener('click', async () => {
            const customerId = Number(button.getAttribute('data-customer-id'));
            const action = button.getAttribute('data-customer-action');
            if (action === 'edit') {
                await selectCustomer(customerId, false);
                clearEditErrors();
                editCustomerModal.show();
                els.editName.focus();
                return;
            }

            await selectCustomer(customerId, action === 'view');
            if (action === 'deactivate') {
                await deactivateCustomer();
            }
        });
    });
}

function renderStatement(entries = []) {
    if (entries.length === 0) {
        els.statementBody.innerHTML = '<tr><td colspan="3" class="text-muted">No ledger entries yet.</td></tr>';
        return;
    }

    els.statementBody.innerHTML = entries.map((entry) => {
        const amountClass = entry.direction === 'debit' ? 'text-danger' : 'text-success';
        return `
            <tr>
                <td>${entry.created_at || entry.created_on || ''}</td>
                <td>${entry.entry_type || ''}</td>
                <td class="text-end ${amountClass}">${formatNgn(entry.amount || 0)}</td>
            </tr>
        `;
    }).join('');
}

function populateSummary(customer, summary) {
    els.summaryEmpty.classList.add('d-none');
    els.summaryPanel.classList.remove('d-none');

    els.name.textContent = customer.full_name || '-';
    els.code.textContent = customer.customer_code || '-';
    els.phone.textContent = customer.phone || '-';
    els.email.textContent = customer.email || '-';
    els.creditLimit.textContent = formatNgn(customer.credit_limit || 0);
    els.openingBalance.textContent = formatNgn(customer.opening_balance || 0);
    els.balance.textContent = formatNgn(summary.balance ?? customer.balance ?? 0);
    state.lastPayment = null;
    els.printStatement.disabled = false;
    els.printReceipt.disabled = true;

    els.amount.value = '';
    els.method.value = 'cash';
    els.reference.value = '';
    els.notes.value = '';
    els.paidAt.value = '';

    els.editName.value = customer.full_name || '';
    els.editPhone.value = customer.phone || '';
    els.editEmail.value = customer.email || '';
    els.editAddress.value = customer.address || '';
    els.editCreditLimit.value = Number(customer.credit_limit || 0);
    els.editOpeningBalance.value = Number(customer.opening_balance || 0);

    els.editForm.dataset.customerId = String(customer.id);
}

async function loadCustomers() {
    const search = els.search.value.trim();
    const response = await apiRequest(`/customers${search ? `?search=${encodeURIComponent(search)}` : ''}`, { method: 'GET' });
    state.customers = response.data || [];
    renderCustomerRows();
}

async function selectCustomer(customerId, openWorkspace = true) {
    state.selectedCustomerId = customerId;

    const customer = state.customers.find((entry) => Number(entry.id) === Number(customerId));
    if (!customer) {
        return;
    }

    const response = await apiRequest(`/customers/${customerId}`, { method: 'GET' });
    state.selectedCustomer = response.data.customer;
    state.statement = response.data.entries || [];

    populateSummary(response.data.customer, response.data.summary || {});
    renderCustomerRows();
    renderStatement(state.statement);
    els.customerWorkspaceTitle.textContent = `${response.data.customer.full_name || 'Customer'} workspace`;
    if (openWorkspace) {
        customerWorkspaceModal.show();
    }
}

async function submitCustomer(event) {
    event.preventDefault();
    clearCreateErrors();

    const payload = {
        full_name: els.createName.value.trim(),
        phone: els.createPhone.value.trim(),
        email: els.createEmail.value.trim(),
        address: els.createAddress.value.trim(),
        credit_limit: Number(els.createCreditLimit.value || 0),
        opening_balance: Number(els.createOpeningBalance.value || 0),
    };

    try {
        const response = await apiRequest('/customers', {
            method: 'POST',
            body: JSON.stringify(payload),
        });

        showToast('Customer created.');
        els.createForm.reset();
        els.createCreditLimit.value = '0';
        els.createOpeningBalance.value = '0';
        newCustomerModal.hide();
        await loadCustomers();
        await selectCustomer(response.data.id);
    } catch (error) {
        setCreateErrors(error?.response?.data?.fields || {});
        showToast(error.message || 'Failed to create customer.', 'danger');
    }
}

async function submitCustomerUpdate(event) {
    event.preventDefault();
    clearEditErrors();

    const customerId = Number(els.editForm.dataset.customerId || 0);
    if (!customerId) {
        showToast('Select a customer first.', 'danger');
        return;
    }

    const payload = {
        full_name: els.editName.value.trim(),
        phone: els.editPhone.value.trim(),
        email: els.editEmail.value.trim(),
        address: els.editAddress.value.trim(),
        credit_limit: Number(els.editCreditLimit.value || 0),
        opening_balance: Number(els.editOpeningBalance.value || 0),
    };

    try {
        await apiRequest(`/customers/${customerId}`, {
            method: 'PUT',
            body: JSON.stringify(payload),
        });

        showToast('Customer updated.');
        editCustomerModal.hide();
        await loadCustomers();
        await selectCustomer(customerId, false);
    } catch (error) {
        setEditErrors(error?.response?.data?.fields || {});
        showToast(error.message || 'Failed to update customer.', 'danger');
    }
}

async function deactivateCustomer() {
    const customerId = Number(els.editForm.dataset.customerId || 0);
    if (!customerId) {
        showToast('Select a customer first.', 'danger');
        return;
    }

    const confirmed = await confirmAction({
        title: 'Deactivate customer',
        message: 'This customer will no longer appear as active.',
        confirmLabel: 'Deactivate',
    });
    if (!confirmed) {
        return;
    }

    try {
        await apiRequest(`/customers/${customerId}`, {
            method: 'DELETE',
        });

        showToast('Customer deactivated.');
        state.selectedCustomerId = null;
        state.selectedCustomer = null;
        state.statement = [];
        els.summaryPanel.classList.add('d-none');
        els.summaryEmpty.classList.remove('d-none');
        els.statementBody.innerHTML = '<tr><td colspan="3" class="text-muted">No customer selected.</td></tr>';
        customerWorkspaceModal.hide();
        await loadCustomers();
    } catch (error) {
        showToast(error.message || 'Failed to deactivate customer.', 'danger');
    }
}

async function submitPayment(event) {
    event.preventDefault();
    clearPaymentErrors();

    if (!state.selectedCustomerId) {
        showToast('Select a customer first.', 'danger');
        return;
    }

    const payload = {
        amount: Number(els.amount.value || 0),
        method: els.method.value,
        reference: els.reference.value.trim(),
        notes: els.notes.value.trim(),
        paid_at: els.paidAt.value ? `${els.paidAt.value.replace('T', ' ')}:00` : '',
    };

    try {
        const response = await apiRequest(`/customers/${state.selectedCustomerId}/payments`, {
            method: 'POST',
            body: JSON.stringify(payload),
        });

        state.lastPayment = response.data.payment || null;
        els.printReceipt.disabled = !state.lastPayment;
        state.statement = response.data.entries || [];
        renderStatement(state.statement);
        await loadCustomers();
        await selectCustomer(state.selectedCustomerId);
        showToast('Payment recorded.');
    } catch (error) {
        setPaymentErrors(error?.response?.data?.fields || {});
        showToast(error.message || 'Failed to record payment.', 'danger');
    }
}

function bindEvents() {
    els.customerWorkspaceBody.append(els.summaryColumn, els.statementColumn);
    els.newCustomerBtn.addEventListener('click', () => {
        clearCreateErrors();
        els.createForm.reset();
        els.createCreditLimit.value = '0';
        els.createOpeningBalance.value = '0';
        newCustomerModal.show();
    });
    els.createForm.addEventListener('submit', submitCustomer);
    els.editForm.addEventListener('submit', submitCustomerUpdate);
    els.searchBtn.addEventListener('click', loadCustomers);
    els.refreshBtn.addEventListener('click', async () => {
        await loadCustomers();
        if (state.selectedCustomerId) {
            await selectCustomer(state.selectedCustomerId, false);
        }
    });
    els.search.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            loadCustomers();
        }
    });
    els.paymentForm.addEventListener('submit', submitPayment);
    els.printStatement.addEventListener('click', printCustomerStatement);
    els.printReceipt.addEventListener('click', printLastPaymentReceipt);
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
        await loadCustomers();
    } catch (error) {
        showToast(error.message || 'Failed to load customers.', 'danger');
    }
}

init();
