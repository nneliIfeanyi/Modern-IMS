import { apiRequest, logout } from './auth.js';

async function loadIncludes() {
    const placeholders = document.querySelectorAll('[data-include]');

    for (const node of placeholders) {
        const partialPath = node.getAttribute('data-include');
        if (!partialPath) {
            continue;
        }

        const response = await fetch(partialPath, { cache: 'no-cache' });
        if (!response.ok) {
            node.innerHTML = `<div class="alert alert-danger m-3">Failed to load ${partialPath}</div>`;
            continue;
        }

        node.innerHTML = await response.text();
    }
}

function renderSmsLogs(logs) {
    const list = document.getElementById('sms-log-list');
    const badge = document.getElementById('sms-log-badge');
    if (!list || !badge) {
        return;
    }

    badge.textContent = String(logs.length);
    badge.classList.toggle('d-none', logs.length === 0);
    list.replaceChildren();

    if (logs.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'px-3 py-3 text-muted small';
        empty.textContent = 'No SMS activity yet.';
        list.append(empty);
        return;
    }

    logs.forEach((log) => {
        const item = document.createElement('div');
        const header = document.createElement('div');
        const event = document.createElement('span');
        const status = document.createElement('span');
        const message = document.createElement('div');
        const meta = document.createElement('div');

        item.className = 'sms-log-item';
        header.className = 'd-flex justify-content-between align-items-center gap-2 mb-1';
        event.className = 'small fw-semibold text-capitalize';
        status.className = `badge text-bg-${log.status === 'sent' ? 'success' : log.status === 'failed' ? 'danger' : 'secondary'}`;
        message.className = 'sms-log-message';
        meta.className = 'text-muted small mt-1';

        event.textContent = String(log.event_type || '').replaceAll('_', ' ');
        status.textContent = log.status || 'pending';
        message.textContent = log.message || '';
        meta.textContent = `${log.recipient || ''} · ${log.created_at || ''}`;

        header.append(event, status);
        item.append(header, message, meta);
        list.append(item);
    });
}

async function loadSmsLogs() {
    if (!document.getElementById('sms-log-list')) {
        return;
    }

    try {
        const response = await apiRequest('/sms-logs?limit=25', { method: 'GET' });
        renderSmsLogs(response.data || []);
    } catch (error) {
        const list = document.getElementById('sms-log-list');
        if (list) {
            list.textContent = 'SMS logs unavailable.';
        }
    }
}

function resolveApiBase() {
    const path = window.location.pathname || '/';
    const lastSlash = path.lastIndexOf('/');
    const dir = lastSlash >= 0 ? path.slice(0, lastSlash) : '';

    if (dir === '' || dir === '/') {
        return '/api';
    }

    return `${dir}/api`;
}

async function applyAppSettings() {
    const apiBase = resolveApiBase();

    try {
        const response = await fetch(`${apiBase}/settings/app`, {
            method: 'GET',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
            },
        });

        if (!response.ok) {
            return;
        }

        const payload = await response.json();
        if (payload?.status !== 'ok' || !payload?.data) {
            return;
        }

        const siteName = (payload.data.site_name || '').trim();
        const themeColor = (payload.data.theme_color || '').trim();

        if (siteName !== '') {
            document.documentElement.dataset.appName = siteName;
            document.querySelectorAll('.logo').forEach((node) => {
                node.textContent = siteName;
            });
            document.title = document.title.includes('|')
                ? document.title.replace(/\s*\|.*$/, ` | ${siteName}`)
                : `${siteName} | ${document.title}`;
        }

        if (/^#[0-9a-fA-F]{6}$/.test(themeColor)) {
            window.applyImsTheme(themeColor);
        }
    } catch (error) {
        // Keep shell rendering even when settings fetch fails.
    }
}

function activateNav() {
    const current = document.body.dataset.nav || '';
    if (!current) {
        return;
    }

    const link = document.querySelector(`[data-nav-key="${current}"]`);
    if (link) {
        link.classList.add('active');
    }
}

function closeSidebarOnMobileNav() {
    const sidebar = document.getElementById('appSidebar');
    if (!sidebar || !window.bootstrap || window.innerWidth >= 768) {
        return;
    }

    const navLinks = sidebar.querySelectorAll('.nav-link');
    navLinks.forEach((link) => {
        link.addEventListener('click', () => {
            const instance = window.bootstrap.Offcanvas.getInstance(sidebar);
            if (instance) {
                instance.hide();
            }
        });
    });
}

function setupSidebarToggle() {
    const body = document.querySelector('.app-body');
    const toggle = document.getElementById('sidebarToggle');
    if (!body || !toggle) {
        return;
    }

    const storageKey = 'ims.sidebar.collapsed';
    const setCollapsed = (collapsed) => {
        body.classList.toggle('sidebar-collapsed', collapsed);
        toggle.setAttribute('aria-expanded', String(!collapsed));
        toggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        toggle.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');

        const icon = toggle.querySelector('.bi');
        if (icon) {
            icon.classList.toggle('bi-chevron-left', !collapsed);
            icon.classList.toggle('bi-chevron-right', collapsed);
        }
    };

    setCollapsed(window.localStorage.getItem(storageKey) === 'true');
    toggle.addEventListener('click', () => {
        const collapsed = !body.classList.contains('sidebar-collapsed');
        setCollapsed(collapsed);
        window.localStorage.setItem(storageKey, String(collapsed));
    });
}

function setupLogout() {
    const logoutLink = document.getElementById('topbar-logout');
    if (!logoutLink) {
        return;
    }

    logoutLink.addEventListener('click', async (event) => {
        event.preventDefault();
        try {
            await logout();
        } catch (error) {
            // Redirect even when the session has already expired or the request fails.
        }
        window.location.href = 'login.html';
    });
}

async function bootShell() {
    await loadIncludes();
    await applyAppSettings();
    activateNav();
    closeSidebarOnMobileNav();
    setupSidebarToggle();
    setupLogout();
    await loadSmsLogs();
}

bootShell();
