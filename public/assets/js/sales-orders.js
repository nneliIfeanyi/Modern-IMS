import { apiRequest, getCurrentUser } from './auth.js';

const state = {
    status: 'all',
    warehouseId: '',
    search: '',
    page: 1,
    perPage: 20,
    totalPages: 1,
};

const els = {
    statusBtns: document.querySelectorAll('[data-so-status]'),
    warehouseFilter: document.getElementById('so-warehouse-filter'),
    search: document.getElementById('so-search'),
    tableBody: document.getElementById('so-table-body'),
    meta: document.getElementById('so-meta'),
    prev: document.getElementById('so-prev'),
    next: document.getElementById('so-next'),
};

function updateStatusButtons(active) {
    els.statusBtns.forEach((btn) => {
        const on = btn.getAttribute('data-so-status') === active;
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
        els.tableBody.innerHTML = '<tr><td colspan="8" class="text-muted">No sales orders found.</td></tr>';
    } else {
        els.tableBody.innerHTML = '';

        for (const so of items) {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${so.so_number}</td>
                <td>${so.customer_name || '-'}</td>
                <td>${so.warehouse_name}</td>
                <td>${so.item_count}</td>
                <td>${formatNgn(so.total_amount)}</td>
                <td><span class="badge text-bg-secondary">${so.status.replace('_', ' ')}</span></td>
                <td>${formatDate(so.created_at)}</td>
                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="sales-order-detail.html?id=${so.id}">Open</a></td>
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

    els.meta.textContent = `Showing ${start}-${end} of ${total} sales orders`;
    els.prev.disabled = page <= 1;
    els.next.disabled = page >= totalPages;
}

async function loadWarehouses() {
    const response = await apiRequest('/warehouses', { method: 'GET' });
    const warehouses = response.data || [];

    els.warehouseFilter.innerHTML = '<option value="">All warehouses</option>';
    warehouses.forEach((warehouse) => {
        const opt = document.createElement('option');
        opt.value = String(warehouse.id);
        opt.textContent = warehouse.name;
        els.warehouseFilter.appendChild(opt);
    });
}

async function loadOrders() {
    const params = new URLSearchParams();
    params.set('page', String(state.page));
    params.set('per_page', String(state.perPage));
    if (state.status !== 'all') {
        params.set('status', state.status);
    }
    if (state.warehouseId) {
        params.set('warehouse_id', state.warehouseId);
    }
    if (state.search) {
        params.set('search', state.search);
    }

    try {
        const response = await apiRequest(`/sales-orders?${params.toString()}`, { method: 'GET' });
        renderOrders(response.data.items || [], response.data.meta || {});
    } catch (error) {
        els.tableBody.innerHTML = `<tr><td colspan="8" class="text-danger">${error.message || 'Failed to load sales orders.'}</td></tr>`;
    }
}

function bindEvents() {
    els.statusBtns.forEach((btn) => {
        btn.addEventListener('click', async () => {
            state.status = btn.getAttribute('data-so-status') || 'all';
            updateStatusButtons(state.status);
            state.page = 1;
            await loadOrders();
        });
    });

    els.warehouseFilter.addEventListener('change', async () => {
        state.warehouseId = els.warehouseFilter.value;
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

    bindEvents();
    await loadWarehouses();
    await loadOrders();
}

init();
