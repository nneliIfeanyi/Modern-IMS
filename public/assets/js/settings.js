import { apiRequest, getCurrentUser, logout } from './auth.js';
import { confirmAction } from './ui.js';

const els = {
    roleBadge: document.getElementById('settings-role-badge'),
    profileForm: document.getElementById('profile-form'),
    fullName: document.getElementById('profile-full-name'),
    email: document.getElementById('profile-email'),
    appSettingsForm: document.getElementById('app-settings-form'),
    siteName: document.getElementById('app-site-name'),
    themeColor: document.getElementById('app-theme-color'),
    categoryForm: document.getElementById('category-form'),
    categoryName: document.getElementById('category-name'),
    categoryDescription: document.getElementById('category-description'),
    categorySubmitBtn: document.getElementById('category-submit-btn'),
    categoryCancelBtn: document.getElementById('category-cancel-btn'),
    categoriesTableBody: document.getElementById('categories-table-body'),
    categoriesEmpty: document.getElementById('categories-empty'),
    passwordForm: document.getElementById('password-form'),
    currentPassword: document.getElementById('password-current'),
    newPassword: document.getElementById('password-new'),
    confirmPassword: document.getElementById('password-confirm'),
    logoutBtn: document.getElementById('settings-logout-btn'),
    toastEl: document.getElementById('settings-toast'),
    toastBody: document.getElementById('settings-toast-body'),
};

const state = {
    editingCategoryId: null,
};

const toast = new bootstrap.Toast(els.toastEl);

function showToast(message, tone = 'success') {
    els.toastBody.textContent = message;
    els.toastEl.className = `toast align-items-center text-bg-${tone} border-0`;
    toast.show();
}

function clearErrors(form) {
    form.querySelectorAll('.is-invalid').forEach((node) => node.classList.remove('is-invalid'));
    form.querySelectorAll('.invalid-feedback').forEach((node) => {
        node.textContent = '';
    });
}

function applyErrors(form, errors = {}) {
    Object.entries(errors).forEach(([field, msg]) => {
        const input = form.querySelector(`[name="${field}"]`);
        if (!input) {
            return;
        }

        input.classList.add('is-invalid');
        const feedback = input.parentElement?.querySelector('.invalid-feedback');
        if (feedback) {
            feedback.textContent = msg;
        }
    });
}

async function loadProfile() {
    const response = await apiRequest('/settings/profile', { method: 'GET' });
    const user = response.data || {};

    els.fullName.value = user.full_name || '';
    els.email.value = user.email || '';
    els.roleBadge.textContent = (user.role || 'unknown').toUpperCase();
}

async function loadAppSettings() {
    const response = await apiRequest('/settings/app', { method: 'GET' });
    const settings = response.data || {};

    els.siteName.value = settings.site_name || 'ANTOBELL IMS';
    els.themeColor.value = settings.theme_color || '#0d6efd';
}

function resetCategoryForm() {
    state.editingCategoryId = null;
    els.categoryForm.reset();
    els.categorySubmitBtn.textContent = 'Add category';
    els.categoryCancelBtn.classList.add('d-none');
    clearErrors(els.categoryForm);
}

function renderCategories(categories) {
    els.categoriesTableBody.replaceChildren();
    els.categoriesEmpty.classList.toggle('d-none', categories.length > 0);

    categories.forEach((category) => {
        const row = document.createElement('tr');
        const name = document.createElement('td');
        const description = document.createElement('td');
        const actions = document.createElement('td');
        const editButton = document.createElement('button');
        const deleteButton = document.createElement('button');

        name.textContent = category.name || '';
        description.textContent = category.description || '-';
        actions.className = 'text-end';

        editButton.type = 'button';
        editButton.className = 'btn btn-sm btn-outline-primary me-1';
        editButton.dataset.action = 'edit-category';
        editButton.dataset.categoryId = String(category.id);
        editButton.textContent = 'Edit';

        deleteButton.type = 'button';
        deleteButton.className = 'btn btn-sm btn-outline-danger';
        deleteButton.dataset.action = 'delete-category';
        deleteButton.dataset.categoryId = String(category.id);
        deleteButton.textContent = 'Delete';

        actions.append(editButton, deleteButton);
        row.append(name, description, actions);
        els.categoriesTableBody.append(row);
    });
}

async function loadCategories() {
    const response = await apiRequest('/categories', { method: 'GET' });
    renderCategories(response.data || []);
}

function startCategoryEdit(category) {
    state.editingCategoryId = Number(category.id);
    els.categoryName.value = category.name || '';
    els.categoryDescription.value = category.description || '';
    els.categorySubmitBtn.textContent = 'Update category';
    els.categoryCancelBtn.classList.remove('d-none');
    clearErrors(els.categoryForm);
    els.categoryName.focus();
}

async function deleteCategory(id) {
    const confirmed = await confirmAction({
        title: 'Delete category',
        message: 'Delete this category? This action cannot be undone.',
        confirmLabel: 'Delete category',
    });
    if (!confirmed) {
        return;
    }

    try {
        await apiRequest(`/categories/${id}`, { method: 'DELETE' });
        if (state.editingCategoryId === id) {
            resetCategoryForm();
        }
        await loadCategories();
        showToast('Category deleted.');
    } catch (error) {
        showToast(error.message || 'Failed to delete category.', 'danger');
    }
}

function applyAppSettingsPreview(siteName, themeColor) {
    if (siteName) {
        document.documentElement.dataset.appName = siteName;
        document.querySelectorAll('.logo').forEach((node) => {
            node.textContent = siteName;
        });
        document.title = document.title.includes('|')
            ? document.title.replace(/\s*\|.*$/, ` | ${siteName}`)
            : `${siteName} | ${document.title}`;
    }

    if (themeColor) {
        const red = Number.parseInt(themeColor.slice(1, 3), 16);
        const green = Number.parseInt(themeColor.slice(3, 5), 16);
        const blue = Number.parseInt(themeColor.slice(5, 7), 16);
        const root = document.documentElement;
        root.style.setProperty('--ims-primary', themeColor);
        root.style.setProperty('--ims-primary-rgb', `${red}, ${green}, ${blue}`);
        root.style.setProperty('--bs-primary', themeColor);
        root.style.setProperty('--bs-primary-rgb', `${red}, ${green}, ${blue}`);
        root.style.setProperty('--bs-focus-ring-color', `rgba(${red}, ${green}, ${blue}, 0.25)`);
    }
}

function bindEvents() {
    els.categoryForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(els.categoryForm);

        const payload = {
            name: els.categoryName.value.trim(),
            description: els.categoryDescription.value.trim(),
        };
        const editingId = state.editingCategoryId;
        const path = editingId ? `/categories/${editingId}` : '/categories';

        try {
            await apiRequest(path, {
                method: editingId ? 'PUT' : 'POST',
                body: JSON.stringify(payload),
            });
            resetCategoryForm();
            await loadCategories();
            showToast(editingId ? 'Category updated.' : 'Category created.');
        } catch (error) {
            applyErrors(els.categoryForm, error?.response?.data?.fields || {});
            showToast(error.message || 'Failed to save category.', 'danger');
        }
    });

    els.categoryCancelBtn.addEventListener('click', resetCategoryForm);
    els.categoriesTableBody.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        const id = Number(button.dataset.categoryId);
        if (button.dataset.action === 'delete-category') {
            deleteCategory(id);
            return;
        }

        const row = button.closest('tr');
        const cells = row?.querySelectorAll('td');
        if (cells?.length >= 2) {
            startCategoryEdit({
                id,
                name: cells[0].textContent,
                description: cells[1].textContent === '-' ? '' : cells[1].textContent,
            });
        }
    });

    els.profileForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(els.profileForm);

        const payload = {
            full_name: els.fullName.value.trim(),
            email: els.email.value.trim(),
        };

        try {
            await apiRequest('/settings/profile', {
                method: 'PUT',
                body: JSON.stringify(payload),
            });
            showToast('Profile updated.');
            await loadProfile();
        } catch (error) {
            applyErrors(els.profileForm, error?.response?.data?.fields || {});
            showToast(error.message || 'Failed to update profile.', 'danger');
        }
    });

    els.passwordForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(els.passwordForm);

        const payload = {
            current_password: els.currentPassword.value,
            new_password: els.newPassword.value,
            confirm_password: els.confirmPassword.value,
        };

        try {
            await apiRequest('/settings/password', {
                method: 'PUT',
                body: JSON.stringify(payload),
            });
            els.passwordForm.reset();
            showToast('Password updated.');
        } catch (error) {
            applyErrors(els.passwordForm, error?.response?.data?.fields || {});
            showToast(error.message || 'Failed to update password.', 'danger');
        }
    });

    els.appSettingsForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(els.appSettingsForm);

        const payload = {
            site_name: els.siteName.value.trim(),
            theme_color: els.themeColor.value.trim(),
        };

        try {
            const response = await apiRequest('/settings/app', {
                method: 'PUT',
                body: JSON.stringify(payload),
            });

            const settings = response.data || payload;
            applyAppSettingsPreview(settings.site_name, settings.theme_color);
            showToast('Brand settings updated.');
        } catch (error) {
            applyErrors(els.appSettingsForm, error?.response?.data?.fields || {});
            showToast(error.message || 'Failed to update brand settings.', 'danger');
        }
    });

    els.logoutBtn.addEventListener('click', async () => {
        try {
            await logout();
        } catch (error) {
            // noop
        }
        window.location.href = 'login.html';
    });
}

async function init() {
    try {
        const me = await getCurrentUser();
        const role = (me.data?.role || '').toLowerCase();
        if (role !== 'admin' && role !== 'manager') {
            showToast('Settings are restricted to admin or manager roles.', 'danger');
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
        await loadProfile();
        await loadAppSettings();
        await loadCategories();
        applyAppSettingsPreview(els.siteName.value, els.themeColor.value);
    } catch (error) {
        showToast(error.message || 'Failed to load profile.', 'danger');
    }
}

init();
