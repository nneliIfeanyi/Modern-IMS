import { apiRequest, getCurrentUser, logout } from './auth.js';
import { confirmAction } from './ui.js';

const els = {
    roleBadge: document.getElementById('settings-role-badge'),
    profileForm: document.getElementById('profile-form'),
    fullName: document.getElementById('profile-full-name'),
    email: document.getElementById('profile-email'),
    userManagementSection: document.getElementById('user-management-section'),
    userForm: document.getElementById('user-form'),
    userFullName: document.getElementById('user-full-name'),
    userEmail: document.getElementById('user-email'),
    userRole: document.getElementById('user-role'),
    userPassword: document.getElementById('user-password'),
    userSubmitBtn: document.getElementById('user-submit-btn'),
    userCancelBtn: document.getElementById('user-cancel-btn'),
    usersTableBody: document.getElementById('users-table-body'),
    usersEmpty: document.getElementById('users-empty'),
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
    editingUserId: null,
    currentUserId: null,
    currentRole: '',
    users: [],
    roles: [],
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

function resetUserForm() {
    state.editingUserId = null;
    els.userForm.reset();
    els.userPassword.required = true;
    els.userSubmitBtn.textContent = 'Create user';
    els.userCancelBtn.classList.add('d-none');
    clearErrors(els.userForm);
}

function renderUsers(users) {
    state.users = users;
    els.usersTableBody.replaceChildren();
    els.usersEmpty.classList.toggle('d-none', users.length > 0);

    users.forEach((user) => {
        const row = document.createElement('tr');
        const name = document.createElement('td');
        const email = document.createElement('td');
        const role = document.createElement('td');
        const status = document.createElement('td');
        const actions = document.createElement('td');
        const editButton = document.createElement('button');
        const statusButton = document.createElement('button');
        const isActive = Number(user.is_active) === 1;

        name.textContent = user.full_name;
        email.textContent = user.email;
        role.textContent = user.role_name;
        status.textContent = isActive ? 'Active' : 'Inactive';
        actions.className = 'text-end text-nowrap';

        editButton.type = 'button';
        editButton.className = 'btn btn-sm btn-outline-primary me-1';
        editButton.dataset.action = 'edit-user';
        editButton.dataset.userId = String(user.id);
        editButton.textContent = 'Edit';

        statusButton.type = 'button';
        statusButton.className = `btn btn-sm ${isActive ? 'btn-outline-danger' : 'btn-outline-success'}`;
        statusButton.dataset.action = 'toggle-user';
        statusButton.dataset.userId = String(user.id);
        statusButton.textContent = isActive ? 'Deactivate' : 'Reactivate';
        if (Number(user.id) === state.currentUserId) {
            statusButton.disabled = true;
            statusButton.title = 'You cannot change your own account status here.';
        }

        actions.append(editButton, statusButton);
        row.append(name, email, role, status, actions);
        els.usersTableBody.append(row);
    });
}

async function loadUsers() {
    const response = await apiRequest('/settings/users', { method: 'GET' });
    renderUsers(response.data || []);
}

async function loadRoles() {
    const response = await apiRequest('/settings/roles', { method: 'GET' });
    state.roles = response.data || [];
    els.userRole.replaceChildren();
    state.roles.forEach((role) => {
        const option = document.createElement('option');
        option.value = String(role.id);
        option.textContent = role.name;
        els.userRole.append(option);
    });
}

function startUserEdit(user) {
    state.editingUserId = Number(user.id);
    els.userFullName.value = user.full_name || '';
    els.userEmail.value = user.email || '';
    els.userRole.value = String(user.role_id);
    els.userPassword.value = '';
    els.userPassword.required = false;
    els.userSubmitBtn.textContent = 'Update user';
    els.userCancelBtn.classList.remove('d-none');
    clearErrors(els.userForm);
    els.userFullName.focus();
}

async function toggleUserStatus(user) {
    const isActive = Number(user.is_active) === 1;
    const confirmed = await confirmAction({
        title: isActive ? 'Deactivate user' : 'Reactivate user',
        message: isActive
            ? `Deactivate ${user.full_name}'s account? They will no longer be able to sign in.`
            : `Reactivate ${user.full_name}'s account?`,
        confirmLabel: isActive ? 'Deactivate user' : 'Reactivate user',
    });
    if (!confirmed) {
        return;
    }

    try {
        if (isActive) {
            await apiRequest(`/settings/users/${user.id}`, { method: 'DELETE' });
        } else {
            await apiRequest(`/settings/users/${user.id}`, {
                method: 'PUT',
                body: JSON.stringify({
                    full_name: user.full_name,
                    email: user.email,
                    role_id: Number(user.role_id),
                    is_active: true,
                }),
            });
        }
        if (state.editingUserId === Number(user.id)) {
            resetUserForm();
        }
        await loadUsers();
        showToast(isActive ? 'User deactivated.' : 'User reactivated.');
    } catch (error) {
        showToast(error.message || 'Failed to change user status.', 'danger');
    }
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
        window.applyImsTheme(themeColor);
    }
}

function bindEvents() {
    els.userForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors(els.userForm);

        const payload = {
            full_name: els.userFullName.value.trim(),
            email: els.userEmail.value.trim(),
            role_id: Number(els.userRole.value),
        };
        const editedUser = state.users.find((user) => Number(user.id) === state.editingUserId);
        if (editedUser) {
            payload.is_active = Number(editedUser.is_active) === 1;
        }
        if (els.userPassword.value !== '') {
            payload.password = els.userPassword.value;
        }

        const editingId = state.editingUserId;
        try {
            await apiRequest(editingId ? `/settings/users/${editingId}` : '/settings/users', {
                method: editingId ? 'PUT' : 'POST',
                body: JSON.stringify(payload),
            });
            resetUserForm();
            await loadUsers();
            showToast(editingId ? 'User updated.' : 'User created.');
        } catch (error) {
            applyErrors(els.userForm, error?.response?.data?.fields || {});
            showToast(error.message || 'Failed to save user.', 'danger');
        }
    });

    els.userCancelBtn.addEventListener('click', resetUserForm);
    els.usersTableBody.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) {
            return;
        }

        const user = state.users.find((item) => Number(item.id) === Number(button.dataset.userId));
        if (!user) {
            return;
        }
        if (button.dataset.action === 'edit-user') {
            startUserEdit(user);
        } else if (button.dataset.action === 'toggle-user') {
            toggleUserStatus(user);
        }
    });

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
        state.currentUserId = Number(me.data?.id || 0);
        const role = (me.data?.role || '').toLowerCase();
        state.currentRole = role;
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
        if (state.currentRole === 'admin') {
            els.userManagementSection.classList.remove('d-none');
            await Promise.all([loadRoles(), loadUsers()]);
            els.userPassword.required = true;
        }
        applyAppSettingsPreview(els.siteName.value, els.themeColor.value);
    } catch (error) {
        showToast(error.message || 'Failed to load profile.', 'danger');
    }
}

init();
