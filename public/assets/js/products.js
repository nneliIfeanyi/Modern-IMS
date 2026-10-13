import { apiRequest, getCurrentUser } from './auth.js';
import { confirmAction } from './ui.js';

const state = {
    page: 1,
    perPage: 20,
    search: '',
    categoryId: '',
    totalPages: 1,
    editingId: null,
};

const els = {
    search: document.getElementById('product-search'),
    categoryFilter: document.getElementById('category-filter'),
    pageSize: document.getElementById('page-size'),
    tableBody: document.getElementById('products-table-body'),
    meta: document.getElementById('products-meta'),
    prevBtn: document.getElementById('prev-page'),
    nextBtn: document.getElementById('next-page'),
    openModalBtn: document.getElementById('open-product-modal-btn'),
    modalTitle: document.getElementById('productModalTitle'),
    form: document.getElementById('product-form'),
    saveBtn: document.getElementById('save-product-btn'),
    toastEl: document.getElementById('app-toast'),
    toastBody: document.getElementById('app-toast-body'),
};

const modalEl = document.getElementById('productModal');
const modal = new bootstrap.Modal(modalEl);
const toast = new bootstrap.Toast(els.toastEl);

function formatNgn(value) {
    const amount = Number(value || 0);
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
        minimumFractionDigits: 2,
    }).format(amount);
}

function showToast(message, tone = 'success') {
    els.toastBody.textContent = message;
    els.toastEl.className = `toast align-items-center text-bg-${tone} border-0`;
    toast.show();
}

function clearFieldErrors() {
    const fields = els.form.querySelectorAll('input, select');
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
        name: document.getElementById('product-name').value.trim(),
        sku: document.getElementById('product-sku').value.trim(),
        category_id: Number(document.getElementById('product-category').value),
        unit: document.getElementById('product-unit').value.trim(),
        cost_price: Number(document.getElementById('product-cost').value),
        selling_price: Number(document.getElementById('product-price').value),
        reorder_level: Number(document.getElementById('product-reorder').value),
    };
}

function validateClient(payload) {
    const errors = {};

    if (!payload.name) {
        errors.name = 'Product name is required.';
    }
    if (!payload.sku) {
        errors.sku = 'SKU is required.';
    }
    if (!payload.category_id) {
        errors.category_id = 'Category is required.';
    }
    if (!payload.unit) {
        errors.unit = 'Unit is required.';
    }
    if (!Number.isFinite(payload.cost_price) || payload.cost_price < 0) {
        errors.cost_price = 'Cost price must be a non-negative number.';
    }
    if (!Number.isFinite(payload.selling_price) || payload.selling_price < 0) {
        errors.selling_price = 'Selling price must be a non-negative number.';
    }
    if (!Number.isInteger(payload.reorder_level) || payload.reorder_level < 0) {
        errors.reorder_level = 'Reorder level must be a non-negative integer.';
    }

    return errors;
}

function resetForm() {
    state.editingId = null;
    els.modalTitle.textContent = 'Add Product';
    els.saveBtn.textContent = 'Save product';
    els.form.reset();
    document.getElementById('product-unit').value = 'piece';
    clearFieldErrors();
}

function setEditMode(product) {
    state.editingId = product.id;
    els.modalTitle.textContent = `Edit Product #${product.id}`;
    els.saveBtn.textContent = 'Update product';

    document.getElementById('product-name').value = product.name;
    document.getElementById('product-sku').value = product.sku;
    document.getElementById('product-category').value = String(product.category_id);
    document.getElementById('product-unit').value = product.unit;
    document.getElementById('product-cost').value = Number(product.cost_price).toFixed(2);
    document.getElementById('product-price').value = Number(product.selling_price).toFixed(2);
    document.getElementById('product-reorder').value = Number(product.reorder_level);
}

function createActionButton(label, variant = 'outline-secondary') {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `btn btn-${variant} btn-sm`;
    btn.textContent = label;
    return btn;
}

function renderProducts(items = [], meta = {}) {
    if (items.length === 0) {
        els.tableBody.innerHTML = '<tr><td colspan="8" class="text-muted">No products found.</td></tr>';
    } else {
        els.tableBody.innerHTML = '';

        items.forEach((product) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
        <td>${product.name}</td>
        <td>${product.sku}</td>
        <td>${product.category_name}</td>
        <td>${Number(product.qty_on_hand || 0)}</td>
        <td>${formatNgn(product.cost_price)}</td>
        <td>${formatNgn(product.selling_price)}</td>
        <td>${Number(product.reorder_level)}</td>
        <td class="text-end"></td>
      `;

            const actions = tr.querySelector('td:last-child');
            const editBtn = createActionButton('Edit');
            editBtn.addEventListener('click', () => {
                setEditMode(product);
                clearFieldErrors();
                modal.show();
            });

            const deleteBtn = createActionButton('Delete', 'outline-danger');
            deleteBtn.addEventListener('click', async () => {
                const ok = await confirmAction({
                    title: 'Delete product',
                    message: `Delete ${product.name}? This action cannot be undone.`,
                    confirmLabel: 'Delete product',
                });
                if (!ok) {
                    return;
                }

                try {
                    await apiRequest(`/products/${product.id}`, { method: 'DELETE' });
                    showToast('Product deleted.');
                    await loadProducts();
                } catch (error) {
                    showToast(error.message || 'Delete failed.', 'danger');
                }
            });

            actions.classList.add('d-flex', 'justify-content-end', 'gap-2');
            actions.append(editBtn, deleteBtn);
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

    els.meta.textContent = `Showing ${start}-${end} of ${total} products`;
    els.prevBtn.disabled = page <= 1;
    els.nextBtn.disabled = page >= totalPages;
}

async function loadCategories() {
    const response = await apiRequest('/categories', { method: 'GET' });
    const categories = response.data || [];

    els.categoryFilter.innerHTML = '<option value="">All categories</option>';
    const formCategory = document.getElementById('product-category');
    formCategory.innerHTML = '<option value="">Select category</option>';

    categories.forEach((category) => {
        const optionFilter = document.createElement('option');
        optionFilter.value = String(category.id);
        optionFilter.textContent = category.name;
        els.categoryFilter.appendChild(optionFilter);

        const optionForm = document.createElement('option');
        optionForm.value = String(category.id);
        optionForm.textContent = category.name;
        formCategory.appendChild(optionForm);
    });
}

async function loadProducts() {
    const params = new URLSearchParams();
    params.set('page', String(state.page));
    params.set('per_page', String(state.perPage));

    if (state.search) {
        params.set('search', state.search);
    }
    if (state.categoryId) {
        params.set('category_id', state.categoryId);
    }

    try {
        const response = await apiRequest(`/products?${params.toString()}`, { method: 'GET' });
        renderProducts(response.data.items || [], response.data.meta || {});
    } catch (error) {
        els.tableBody.innerHTML = `<tr><td colspan="8" class="text-danger">${error.message || 'Failed to load products.'}</td></tr>`;
    }
}

function bindEvents() {
    let searchTimer = null;

    els.search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(async () => {
            state.search = els.search.value.trim();
            state.page = 1;
            await loadProducts();
        }, 300);
    });

    els.categoryFilter.addEventListener('change', async () => {
        state.categoryId = els.categoryFilter.value;
        state.page = 1;
        await loadProducts();
    });

    els.pageSize.addEventListener('change', async () => {
        state.perPage = Number(els.pageSize.value);
        state.page = 1;
        await loadProducts();
    });

    els.prevBtn.addEventListener('click', async () => {
        if (state.page > 1) {
            state.page -= 1;
            await loadProducts();
        }
    });

    els.nextBtn.addEventListener('click', async () => {
        if (state.page < state.totalPages) {
            state.page += 1;
            await loadProducts();
        }
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
                await apiRequest('/products', {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
                showToast('Product created.');
            } else {
                await apiRequest(`/products/${state.editingId}`, {
                    method: 'PUT',
                    body: JSON.stringify(payload),
                });
                showToast('Product updated.');
            }

            modal.hide();
            resetForm();
            await loadProducts();
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
    await loadCategories();
    await loadProducts();
}

init();
