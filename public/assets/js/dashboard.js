import { apiRequest, getCurrentUser } from './auth.js';

const state = {
    trendDays: 30,
};

const els = {
    kpiSkus: document.getElementById('kpi-total-skus'),
    kpiStockValue: document.getElementById('kpi-stock-value'),
    kpiLowStock: document.getElementById('kpi-low-stock'),
    kpiPendingOrders: document.getElementById('kpi-pending-orders'),
    kpiPendingBreakdown: document.getElementById('kpi-pending-breakdown'),
    lowStockList: document.getElementById('low-stock-list'),
    lowStockMeta: document.getElementById('low-stock-meta'),
    recentBody: document.getElementById('recent-movements-body'),
    trendDaysLabel: document.getElementById('trend-days-label'),
    trendCanvas: document.getElementById('movement-trend-chart'),
};

let trendChart = null;

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

function formatDateTime(value) {
    if (!value) {
        return '-';
    }

    const dt = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(dt.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-NG', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(dt);
}

function movementTypeLabel(type) {
    return ({
        in: 'Stock in',
        out: 'Stock out',
        adjustment: 'Adjustment',
        transfer: 'Transfer',
    }[type] || type);
}

function movementTypeTone(type) {
    return ({
        in: 'success',
        out: 'danger',
        transfer: 'primary',
        adjustment: 'secondary',
    }[type] || 'secondary');
}

function renderSummary(summary) {
    els.kpiSkus.textContent = Number(summary.total_skus || 0).toLocaleString('en-NG');
    els.kpiStockValue.textContent = formatNgn(summary.total_stock_value || 0);
    els.kpiLowStock.textContent = Number(summary.low_stock_count || 0).toLocaleString('en-NG');

    const pending = summary.pending_orders || {};
    els.kpiPendingOrders.textContent = Number(pending.total || 0).toLocaleString('en-NG');
    els.kpiPendingBreakdown.textContent = `${Number(pending.purchase || 0)} purchase / ${Number(pending.sales || 0)} sales`;
}

function renderLowStock(items) {
    if (!items || items.length === 0) {
        els.lowStockList.innerHTML = '<li class="list-group-item text-muted">No low-stock items.</li>';
        els.lowStockMeta.textContent = 'All products are above reorder levels.';
        return;
    }

    els.lowStockList.innerHTML = '';

    items.forEach((item) => {
        const li = document.createElement('li');
        li.className = 'list-group-item d-flex justify-content-between align-items-start gap-3';
        li.innerHTML = `
            <div>
                <a class="fw-semibold text-decoration-none" href="products.html?search=${encodeURIComponent(item.sku)}">${item.name}</a>
                <div class="small text-muted">${item.sku} • Reorder: ${Number(item.reorder_level)}</div>
            </div>
            <div class="text-end">
                <div class="fw-semibold">${Number(item.qty_on_hand)} left</div>
                <div class="small text-danger">Short by ${Math.max(0, Number(item.shortage || 0))}</div>
            </div>
        `;
        els.lowStockList.appendChild(li);
    });

    els.lowStockMeta.textContent = `${items.length} item${items.length > 1 ? 's' : ''} need reorder attention.`;
}

function renderRecentMovements(items) {
    if (!items || items.length === 0) {
        els.recentBody.innerHTML = '<tr><td colspan="5" class="text-muted">No movements yet.</td></tr>';
        return;
    }

    els.recentBody.innerHTML = '';

    items.forEach((item) => {
        const tr = document.createElement('tr');
        const qty = Number(item.quantity || 0);
        const qtyText = qty > 0 ? `+${qty}` : `${qty}`;
        const type = String(item.movement_type || '');

        tr.innerHTML = `
            <td>${formatDateTime(item.created_at)}</td>
            <td>${item.product_name} <span class="text-muted small">(${item.sku})</span></td>
            <td><span class="badge text-bg-${movementTypeTone(type)}">${movementTypeLabel(type)}</span></td>
            <td>${qtyText}</td>
            <td>${item.reference || '-'}</td>
        `;

        els.recentBody.appendChild(tr);
    });
}

function renderTrend(trend) {
    const items = trend.items || [];
    const labels = items.map((item) => formatDate(item.date));
    const stockIn = items.map((item) => Number(item.stock_in || 0));
    const stockOut = items.map((item) => Number(item.stock_out || 0));

    els.trendDaysLabel.textContent = `Last ${trend.days || state.trendDays} days`;

    if (trendChart) {
        trendChart.destroy();
    }

    trendChart = new Chart(els.trendCanvas, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Stock In',
                    data: stockIn,
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25, 135, 84, 0.16)',
                    tension: 0.25,
                    fill: true,
                },
                {
                    label: 'Stock Out',
                    data: stockOut,
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.12)',
                    tension: 0.25,
                    fill: true,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                },
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0,
                    },
                },
            },
        },
    });
}

async function loadDashboard() {
    const [summaryRes, trendRes, lowStockRes, recentRes] = await Promise.all([
        apiRequest('/dashboard/summary', { method: 'GET' }),
        apiRequest(`/dashboard/movements-trend?days=${state.trendDays}`, { method: 'GET' }),
        apiRequest('/dashboard/low-stock', { method: 'GET' }),
        apiRequest('/stock-movements?limit=5&page=1', { method: 'GET' }),
    ]);

    renderSummary(summaryRes.data || {});
    renderTrend(trendRes.data || { days: state.trendDays, items: [] });
    renderLowStock(lowStockRes.data || []);
    renderRecentMovements(recentRes.data.items || []);
}

async function init() {
    try {
        await getCurrentUser();
    } catch (error) {
        window.location.href = 'login.html';
        return;
    }

    try {
        await loadDashboard();
    } catch (error) {
        els.lowStockList.innerHTML = `<li class="list-group-item text-danger">${error.message || 'Failed to load dashboard data.'}</li>`;
        els.recentBody.innerHTML = `<tr><td colspan="5" class="text-danger">${error.message || 'Failed to load dashboard data.'}</td></tr>`;
    }
}

init();
