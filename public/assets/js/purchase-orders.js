import { apiRequest, getCurrentUser } from './auth.js';

const pageUrl = new URL(window.location.href);
const initialSupplierId = pageUrl.searchParams.get('supplier_id') || '';
const initialSearch = pageUrl.searchParams.get('search') || '';

const state = {
    status: 'all',
    supplierId: initialSupplierId,
    search: initialSearch,
    page: 1,
    perPage: 20,
    totalPages: 1,
};

const els = {
    statusBtns: document.querySelectorAll('[data-po-status]'),
    supplierFilter: document.getElementById('po-supplier-filter'),
    search: document.getElementById('po-search'),
    tableBody: document.getElementById('po-table-body'),
    meta: document.getElementById('po-meta'),
    prev: document.getElementById('po-prev'),
    next: document.getElementById('po-next'),
};

function updateStatusButtons(active) {
    els.statusBtns.forEach((btn) => {
        const on = btn.getAttribute('data-po-status') === active;
        btn.classList.toggle('active', on);
    });
}

function formatNgn(value) {
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
        minimumFractionDigits: 2,
    }).format(Number(value || 0));
}

function formatDate(value) {
    if (!value) {
        return '-';
    }
    const dt = new Date(value);
    if (Number.isNaN(dt.getTime())) {
        return value;
    }
    return new Intl.DateTimeFormat('en-NG', { dateStyle: 'medium' }).format(dt);
}

function renderOrders(items, meta) {
    if (!items || items.length === 0) {
        els.tableBody.innerHTML = '<tr><td colspan="8" class="text-muted">No purchase orders found.</td></tr>';
    } else {
        els.tableBody.innerHTML = '';

        for (const po of items) {
            const tr = document.createElement('tr');
            tr.innerHTML = `
        <td>${po.po_number}</td>
        <td>${po.supplier_name}</td>
        <td>${po.item_count}</td>
        <td>${formatNgn(po.total_amount)}</td>
        <td><span class="badge text-bg-secondary">${po.status.replace('_', ' ')}</span></td>
        <td>${formatDate(po.created_at)}</td>
        <td>${po.expected_date || '-'}</td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="purchase-order-detail.html?id=${po.id}">Open</a></td>
      `;
            els.tableBody.appendChild(tr);
        }
    }

    const page = Number(meta?.page || 1);
    const per = Number(meta?.per_page || state.perPage);
    const total = Number(meta?.total || 0);
    const totalPages = Number(meta?.total_pages || 1);
    state.totalPages = totalPages;

    const start = total === 0 ? 0 : (page - 1) * per + 1;
    const end = Math.min(page * per, total);

    els.meta.textContent = `Showing ${start}-${end} of ${total} purchase orders`;
    els.prev.disabled = page <= 1;
    els.next.disabled = page >= totalPages;
}

async function loadSuppliers() {
    const response = await apiRequest('/suppliers', { method: 'GET' });
    const suppliers = response.data || [];

    els.supplierFilter.innerHTML = '<option value="">All suppliers</option>';
    suppliers.forEach((supplier) => {
        const opt = document.createElement('option');
        opt.value = String(supplier.id);
        opt.textContent = supplier.name;
        els.supplierFilter.appendChild(opt);
    });

    if (state.supplierId) {
        els.supplierFilter.value = String(state.supplierId);
    }
}

async function loadOrders() {
    const params = new URLSearchParams();
    params.set('page', String(state.page));
    params.set('per_page', String(state.perPage));
    if (state.status !== 'all') {
        params.set('status', state.status);
    }
    if (state.supplierId) {
        params.set('supplier_id', state.supplierId);
    }
    if (state.search) {
        params.set('search', state.search);
    }

    try {
        const response = await apiRequest(`/purchase-orders?${params.toString()}`, { method: 'GET' });
        renderOrders(response.data.items || [], response.data.meta || {});
    } catch (error) {
        els.tableBody.innerHTML = `<tr><td colspan="8" class="text-danger">${error.message || 'Failed to load purchase orders.'}</td></tr>`;
    }
}

function bindEvents() {
    els.statusBtns.forEach((btn) => {
        btn.addEventListener('click', async () => {
            state.status = btn.getAttribute('data-po-status') || 'all';
            updateStatusButtons(state.status);
            state.page = 1;
            await loadOrders();
        });
    });

    els.supplierFilter.addEventListener('change', async () => {
        state.supplierId = els.supplierFilter.value;
        state.page = 1;
        await loadOrders();
    });

    let searchTimer = null;
    els.search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(async () => {
            state.search = els.search.value.trim();
            state.page = 1;
            await loadOrders();
        }, 300);
    });

    els.prev.addEventListener('click', async () => {
        if (state.page > 1) {
            state.page -= 1;
            await loadOrders();
        }
    });

    els.next.addEventListener('click', async () => {
        if (state.page < state.totalPages) {
            state.page += 1;
            await loadOrders();
        }
    });
}

async function init() {
    try {
        await getCurrentUser();
    } catch (error) {
        window.location.href = 'login.html';
        return;
    }

    if (state.search) {
        els.search.value = state.search;
    }

    bindEvents();
    await loadSuppliers();
    await loadOrders();
}

init();
