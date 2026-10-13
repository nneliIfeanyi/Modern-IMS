import { apiRequest, getCurrentUser } from './auth.js';

const state = {
    valuationSearch: '',
    movementSearch: '',
    movementPage: 1,
    movementPerPage: 20,
    movementType: 'all',
    movementWarehouseId: '',
    movementDateFrom: '',
    movementDateTo: '',
    lowStockSearch: '',
};

const els = {
    valuationBody: document.getElementById('valuation-body'),
    valuationMeta: document.getElementById('valuation-meta'),
    valuationSearch: document.getElementById('valuation-search'),
    lowStockBody: document.getElementById('low-stock-body'),
    lowStockMeta: document.getElementById('low-stock-meta'),
    lowStockSearch: document.getElementById('low-stock-search'),
    movementBody: document.getElementById('movement-body'),
    movementMeta: document.getElementById('movement-meta'),
    movementSearch: document.getElementById('movement-search'),
    movementType: document.getElementById('movement-type'),
    movementWarehouse: document.getElementById('movement-warehouse'),
    movementDateFrom: document.getElementById('movement-date-from'),
    movementDateTo: document.getElementById('movement-date-to'),
    movementPrev: document.getElementById('movement-prev'),
    movementNext: document.getElementById('movement-next'),
    exportCsv: document.getElementById('export-csv-btn'),
    toastEl: document.getElementById('reports-toast'),
    toastBody: document.getElementById('reports-toast-body'),
};

const toast = new bootstrap.Toast(els.toastEl);

function showToast(message, tone = 'success') {
    els.toastBody.textContent = message;
    els.toastEl.className = `toast align-items-center text-bg-${tone} border-0`;
    toast.show();
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

    const date = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-GB', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
}

async function loadWarehouses() {
    const response = await apiRequest('/warehouses', { method: 'GET' });
    const items = response.data || [];

    els.movementWarehouse.innerHTML = '<option value="">All warehouses</option>';
    items.forEach((warehouse) => {
        const option = document.createElement('option');
        option.value = String(warehouse.id);
        option.textContent = warehouse.name;
        els.movementWarehouse.appendChild(option);
    });
}

function getMovementParams(includePaging = true) {
    const params = new URLSearchParams();

    if (state.movementType && state.movementType !== 'all') {
        params.set('type', state.movementType);
    }
    if (state.movementWarehouseId) {
        params.set('warehouse_id', state.movementWarehouseId);
    }
    if (state.movementDateFrom) {
        params.set('date_from', state.movementDateFrom);
    }
    if (state.movementDateTo) {
        params.set('date_to', state.movementDateTo);
    }
    if (state.movementSearch) {
        params.set('search', state.movementSearch);
    }

    if (includePaging) {
        params.set('page', String(state.movementPage));
        params.set('per_page', String(state.movementPerPage));
    }

    return params;
}

function renderValuation(data) {
    const items = data.items || [];
    if (items.length === 0) {
        els.valuationBody.innerHTML = '<tr><td colspan="6" class="text-muted">No valuation rows found.</td></tr>';
        els.valuationMeta.textContent = 'No rows';
        return;
    }

    els.valuationBody.innerHTML = '';
    items.forEach((item) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${item.sku}</td>
            <td>${item.name}</td>
            <td>${item.category_name || '-'}</td>
            <td>${Number(item.qty_on_hand || 0)}</td>
            <td>${formatNgn(item.cost_price || 0)}</td>
            <td>${formatNgn(item.stock_value || 0)}</td>
        `;
        els.valuationBody.appendChild(tr);
    });

    const summary = data.summary || {};
    els.valuationMeta.textContent = `Rows: ${summary.item_count || items.length} | Total qty: ${Number(summary.total_quantity || 0)} | Total value: ${formatNgn(summary.total_value || 0)}`;
}

function renderLowStock(items) {
    if (items.length === 0) {
        els.lowStockBody.innerHTML = '<tr><td colspan="4" class="text-muted">No low-stock items.</td></tr>';
        els.lowStockMeta.textContent = '0 low-stock items';
        return;
    }

    els.lowStockBody.innerHTML = '';
    items.forEach((item) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${item.sku}</td>
            <td>${item.name}</td>
            <td>${Number(item.quantity_on_hand || 0)}</td>
            <td>${Number(item.reorder_level || 0)}</td>
        `;
        els.lowStockBody.appendChild(tr);
    });

    els.lowStockMeta.textContent = `${items.length} low-stock item${items.length > 1 ? 's' : ''}`;
}

function renderMovements(data) {
    const items = data.items || [];
    const meta = data.meta || {};

    if (items.length === 0) {
        els.movementBody.innerHTML = '<tr><td colspan="9" class="text-muted">No movements found.</td></tr>';
        els.movementMeta.textContent = 'Showing 0 rows';
        els.movementPrev.disabled = true;
        els.movementNext.disabled = true;
        return;
    }

    els.movementBody.innerHTML = '';
    items.forEach((item) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${formatDate(item.created_at)}</td>
            <td><span class="badge text-bg-light">${item.movement_type}</span></td>
            <td>${item.sku}</td>
            <td>${item.product_name}</td>
            <td>${item.warehouse_name}</td>
            <td>${Number(item.quantity || 0)}</td>
            <td>${item.reference || '-'}</td>
            <td>${item.created_by_name || '-'}</td>
            <td>${item.notes || '-'}</td>
        `;
        els.movementBody.appendChild(tr);
    });

    const page = Number(meta.page || 1);
    const total = Number(meta.total || items.length);
    const perPage = Number(meta.per_page || items.length);
    const totalPages = Number(meta.total_pages || 1);
    const start = (page - 1) * perPage + 1;
    const end = Math.min(total, start + items.length - 1);

    els.movementMeta.textContent = `Showing ${start}-${end} of ${total}`;
    els.movementPrev.disabled = page <= 1;
    els.movementNext.disabled = page >= totalPages;
}

async function loadValuation() {
    const params = new URLSearchParams();
    if (state.valuationSearch) {
        params.set('search', state.valuationSearch);
    }

    const suffix = params.toString() ? `?${params.toString()}` : '';
    const response = await apiRequest(`/reports/stock-valuation${suffix}`, { method: 'GET' });
    renderValuation(response.data || { items: [], summary: {} });
}

async function loadLowStock() {
    const params = new URLSearchParams();
    if (state.lowStockSearch) {
        params.set('search', state.lowStockSearch);
    }

    const suffix = params.toString() ? `?${params.toString()}` : '';
    const response = await apiRequest(`/reports/low-stock${suffix}`, { method: 'GET' });
    renderLowStock(response.data?.items || []);
}

async function loadMovements() {
    const params = getMovementParams(true);
    const suffix = params.toString() ? `?${params.toString()}` : '';
    const response = await apiRequest(`/reports/movement-history${suffix}`, { method: 'GET' });
    renderMovements(response.data || { items: [], meta: {} });
}

function bindEvents() {
    let valuationTimer = null;
    let lowStockTimer = null;
    let movementTimer = null;

    els.valuationSearch.addEventListener('input', () => {
        clearTimeout(valuationTimer);
        valuationTimer = setTimeout(async () => {
            state.valuationSearch = els.valuationSearch.value.trim();
            await loadValuation();
        }, 300);
    });

    els.lowStockSearch.addEventListener('input', () => {
        clearTimeout(lowStockTimer);
        lowStockTimer = setTimeout(async () => {
            state.lowStockSearch = els.lowStockSearch.value.trim();
            await loadLowStock();
        }, 300);
    });

    els.movementSearch.addEventListener('input', () => {
        clearTimeout(movementTimer);
        movementTimer = setTimeout(async () => {
            state.movementSearch = els.movementSearch.value.trim();
            state.movementPage = 1;
            await loadMovements();
        }, 300);
    });

    els.movementType.addEventListener('change', async () => {
        state.movementType = els.movementType.value;
        state.movementPage = 1;
        await loadMovements();
    });

    els.movementWarehouse.addEventListener('change', async () => {
        state.movementWarehouseId = els.movementWarehouse.value;
        state.movementPage = 1;
        await loadMovements();
    });

    els.movementDateFrom.addEventListener('change', async () => {
        state.movementDateFrom = els.movementDateFrom.value;
        state.movementPage = 1;
        await loadMovements();
    });

    els.movementDateTo.addEventListener('change', async () => {
        state.movementDateTo = els.movementDateTo.value;
        state.movementPage = 1;
        await loadMovements();
    });

    els.movementPrev.addEventListener('click', async () => {
        if (state.movementPage <= 1) {
            return;
        }
        state.movementPage -= 1;
        await loadMovements();
    });

    els.movementNext.addEventListener('click', async () => {
        state.movementPage += 1;
        await loadMovements();
    });

    els.exportCsv.addEventListener('click', () => {
        const params = getMovementParams(false);
        params.set('export', 'csv');
        const href = `api/reports/movement-history?${params.toString()}`;
        window.open(href, '_blank');
    });
}

async function init() {
    try {
        const me = await getCurrentUser();
        const role = (me.data?.role || '').toLowerCase();
        if (role !== 'admin' && role !== 'manager') {
            showToast('Reports are restricted to admin or manager roles.', 'danger');
            setTimeout(() => {
                window.location.href = 'dashboard.html';
            }, 800);
            return;
        }
    } catch (error) {
        window.location.href = 'login.html';
        return;
    }

    bindEvents();

    try {
        await loadWarehouses();
        await Promise.all([
            loadValuation(),
            loadLowStock(),
            loadMovements(),
        ]);
    } catch (error) {
        showToast(error.message || 'Failed to load reports.', 'danger');
    }
}

init();
