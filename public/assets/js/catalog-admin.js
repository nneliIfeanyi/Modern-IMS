import { apiRequest, getCurrentUser } from './auth.js';

const state = {
    items: [],
    editingId: null,
    previewUrl: null,
};

const catalogPath = document.body.dataset.catalogPath;
const catalogVariantField = document.body.dataset.catalogVariantField || 'model';
const catalogLabel = document.body.dataset.catalogLabel || 'Product';

const els = {
    alert: document.getElementById('catalogue-alert'),
    form: document.getElementById('catalogue-form'),
    formTitle: document.getElementById('catalogue-form-title'),
    saveButton: document.getElementById('save-catalogue-item'),
    cancelButton: document.getElementById('cancel-catalogue-edit'),
    resetButton: document.getElementById('reset-catalogue-form'),
    image: document.getElementById('catalogue-image'),
    imagePreview: document.getElementById('catalogue-image-preview'),
    tableBody: document.getElementById('catalogue-table-body'),
    count: document.getElementById('catalogue-count'),
};

function showAlert(message, tone = 'danger') {
    els.alert.className = `alert alert-${tone}`;
    els.alert.textContent = message;
    els.alert.classList.remove('d-none');
}

function clearAlert() {
    els.alert.classList.add('d-none');
    els.alert.textContent = '';
}

function formatPrice(value) {
    if (value === null || value === undefined || value === '') {
        return 'Ask for price';
    }

    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
        minimumFractionDigits: 2,
    }).format(Number(value));
}

function imageUrl(path) {
    if (/^https?:\/\//i.test(path)) {
        return path;
    }

    return new URL(`../${path}`, document.baseURI).href;
}

function setImagePreview(path) {
    if (state.previewUrl) {
        URL.revokeObjectURL(state.previewUrl);
        state.previewUrl = null;
    }

    if (!path) {
        els.imagePreview.removeAttribute('src');
        els.imagePreview.classList.add('d-none');
        return;
    }

    els.imagePreview.src = imageUrl(path);
    els.imagePreview.classList.remove('d-none');
}

function makeActionButton(label, className) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `btn btn-sm ${className}`;
    button.textContent = label;
    return button;
}

function renderItems() {
    els.tableBody.replaceChildren();
    els.count.textContent = `${state.items.length} ${state.items.length === 1 ? 'product' : 'products'}`;

    if (state.items.length === 0) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 5;
        cell.className = 'text-muted';
        cell.textContent = 'No catalogue products yet. Add one using the form.';
        row.append(cell);
        els.tableBody.append(row);
        return;
    }

    state.items.forEach((item) => {
        const row = document.createElement('tr');
        const productCell = document.createElement('td');
        const productWrap = document.createElement('div');
        const image = document.createElement('img');
        const name = document.createElement('div');
        const details = document.createElement('small');
        const category = document.createElement('td');
        const price = document.createElement('td');
        const status = document.createElement('td');
        const actions = document.createElement('td');
        const actionWrap = document.createElement('div');
        const editButton = makeActionButton('Edit', 'btn-outline-primary');
        const deleteButton = makeActionButton('Delete', 'btn-outline-danger');

        productWrap.className = 'd-flex align-items-center gap-2';
        image.src = imageUrl(item.image_path);
        image.alt = '';
        image.width = 48;
        image.height = 48;
        image.className = 'rounded border';
        image.style.objectFit = 'cover';
        name.className = 'fw-semibold';
        name.textContent = item.name;
        details.className = 'd-block text-muted';
        details.textContent = [item.label, item.brand, item[catalogVariantField]].filter(Boolean).join(' · ');
        name.append(details);
        productWrap.append(image, name);
        productCell.append(productWrap);
        category.textContent = item.category;
        price.textContent = formatPrice(item.display_price);
        status.append(Object.assign(document.createElement('span'), {
            className: `badge text-bg-${Number(item.is_published) === 1 ? 'success' : 'secondary'}`,
            textContent: Number(item.is_published) === 1 ? 'Published' : 'Draft',
        }));
        actions.className = 'text-end';
        actionWrap.className = 'd-inline-flex gap-1';
        editButton.addEventListener('click', () => beginEdit(item));
        deleteButton.addEventListener('click', () => deleteItem(item));
        actionWrap.append(editButton, deleteButton);
        actions.append(actionWrap);
        row.append(productCell, category, price, status, actions);
        els.tableBody.append(row);
    });
}

async function loadItems() {
    const response = await apiRequest(catalogPath, { method: 'GET' });
    state.items = response.data || [];
    renderItems();
}

function resetForm() {
    state.editingId = null;
    els.form.reset();
    document.getElementById('catalogue-sort-order').value = '0';
    els.formTitle.textContent = 'Add a catalogue product';
    els.saveButton.textContent = 'Save product';
    els.cancelButton.classList.add('d-none');
    els.image.required = true;
    setImagePreview('');
}

function beginEdit(item) {
    state.editingId = Number(item.id);
    els.image.value = '';
    document.getElementById('catalogue-name').value = item.name || '';
    document.getElementById('catalogue-category').value = item.category || '';
    const labelInput = document.getElementById('catalogue-label');
    if (labelInput) {
        labelInput.value = item.label || '';
    }
    document.getElementById('catalogue-price').value = item.display_price ?? '';
    document.getElementById('catalogue-brand').value = item.brand || '';
    document.getElementById('catalogue-model').value = item[catalogVariantField] || '';
    document.getElementById('catalogue-description').value = item.description || '';
    document.getElementById('catalogue-sort-order').value = item.sort_order ?? 0;
    document.getElementById('catalogue-published').checked = Number(item.is_published) === 1;
    els.formTitle.textContent = `Edit ${item.name}`;
    els.saveButton.textContent = 'Update product';
    els.cancelButton.classList.remove('d-none');
    els.image.required = false;
    setImagePreview(item.image_path);
    els.form.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function submitForm(event) {
    event.preventDefault();
    clearAlert();

    if (!els.form.reportValidity()) {
        return;
    }

    const formData = new FormData(els.form);
    if (els.image.files.length === 0) {
        formData.delete('image');
    }
    formData.set('is_published', document.getElementById('catalogue-published').checked ? '1' : '0');
    els.saveButton.disabled = true;

    try {
        const path = state.editingId ? `${catalogPath}/${state.editingId}` : catalogPath;
        await apiRequest(path, {
            method: 'POST',
            body: formData,
        });
        showAlert(state.editingId ? 'Catalogue product updated.' : 'Catalogue product added.', 'success');
        resetForm();
        await loadItems();
    } catch (error) {
        const fieldErrors = error.response?.data?.fields;
        const detail = fieldErrors ? Object.values(fieldErrors).join(' ') : '';
        showAlert(detail || error.message || 'The catalogue product could not be saved.');
    } finally {
        els.saveButton.disabled = false;
    }
}

async function deleteItem(item) {
    if (!window.confirm(`Delete "${item.name}" from the ${catalogLabel.toLowerCase()} catalogue?`)) {
        return;
    }

    clearAlert();
    try {
        await apiRequest(`${catalogPath}/${item.id}`, { method: 'DELETE' });
        if (state.editingId === Number(item.id)) {
            resetForm();
        }
        await loadItems();
        showAlert('Catalogue product deleted.', 'success');
    } catch (error) {
        showAlert(error.message || 'The catalogue product could not be deleted.');
    }
}

async function initialize() {
    let currentUser;
    try {
        currentUser = await getCurrentUser();
    } catch (error) {
        window.location.href = 'login.html';
        return;
    }

    const role = (currentUser.data?.role || '').toLowerCase();
    if (role !== 'admin' && role !== 'manager') {
        showAlert(`${catalogLabel} catalogue management is restricted to admin or manager roles.`);
        window.location.href = 'dashboard.html';
        return;
    }

    try {
        await loadItems();
    } catch (error) {
        showAlert(error.message || 'The gadget catalogue could not be loaded.');
    }
}

els.form.addEventListener('submit', submitForm);
els.resetButton.addEventListener('click', resetForm);
els.cancelButton.addEventListener('click', resetForm);
els.image.addEventListener('change', () => {
    const file = els.image.files[0];
    if (file) {
        setImagePreview('');
        state.previewUrl = URL.createObjectURL(file);
        els.imagePreview.src = state.previewUrl;
        els.imagePreview.classList.remove('d-none');
    } else if (state.editingId) {
        const item = state.items.find((product) => Number(product.id) === state.editingId);
        setImagePreview(item?.image_path);
    } else {
        setImagePreview('');
    }
});

initialize();
