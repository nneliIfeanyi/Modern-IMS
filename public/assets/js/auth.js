function resolveApiBase() {
    const path = window.location.pathname || '/';
    const lastSlash = path.lastIndexOf('/');
    const dir = lastSlash >= 0 ? path.slice(0, lastSlash) : '';

    if (dir === '' || dir === '/') {
        return '/api';
    }

    return `${dir}/api`;
}

const API_BASE = resolveApiBase();

async function apiRequest(path, options = {}) {
    const response = await fetch(`${API_BASE}${path}`, {
        credentials: 'include',
        headers: {
            'Content-Type': 'application/json',
            ...(options.headers || {}),
        },
        ...options,
    });

    let payload = null;
    try {
        payload = await response.json();
    } catch (error) {
        payload = { status: 'error', data: null, message: 'Invalid JSON response.' };
    }

    if (!response.ok || payload.status === 'error') {
        const err = new Error(payload.message || 'Request failed.');
        err.response = payload;
        err.status = response.status;
        throw err;
    }

    return payload;
}

export async function login(email, password) {
    return apiRequest('/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
    });
}

export async function logout() {
    return apiRequest('/auth/logout', {
        method: 'POST',
        body: JSON.stringify({}),
    });
}

export async function getCurrentUser() {
    return apiRequest('/auth/me', {
        method: 'GET',
    });
}

export { apiRequest };
