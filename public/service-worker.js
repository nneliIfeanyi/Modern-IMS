const CACHE_NAME = 'stanvic-ims-shell-v2';
const SHELL_FILES = [
    'login.html',
    'manifest.webmanifest',
    'assets/img/pwa-icon.svg',
    'assets/css/app-shell.css',
    'assets/css/login.css',
    'assets/js/auth.js',
    'assets/js/include.js',
    'assets/js/pwa.js',
    'assets/js/theme-init.js',
    'assets/vendor/bootstrap/css/bootstrap.min.css',
    'assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
    'assets/vendor/bootstrap-icons/bootstrap-icons.css',
    'assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(SHELL_FILES.map((file) => new URL(file, self.registration.scope))))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => Promise.all(
                cacheNames
                    .filter((cacheName) => cacheName.endsWith('-ims-shell-v1')
                        || (cacheName.startsWith('stanvic-ims-') && cacheName !== CACHE_NAME))
                    .map((cacheName) => caches.delete(cacheName))
            ))
            .then(() => self.clients.claim())
    );
});

async function fetchAndCache(request) {
    const cache = await caches.open(CACHE_NAME);

    try {
        const response = await fetch(request);
        if (response.ok && response.type === 'basic') {
            await cache.put(request, response.clone());
        }
        return response;
    } catch (error) {
        const cachedResponse = await cache.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }
        throw error;
    }
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const requestUrl = new URL(request.url);

    if (request.method !== 'GET' || requestUrl.origin !== self.location.origin) {
        return;
    }

    if (requestUrl.pathname.includes('/api/')) {
        return;
    }

    if (request.mode === 'navigate') {
        if (!requestUrl.pathname.endsWith('.html') || requestUrl.search) {
            return;
        }

        event.respondWith(fetchAndCache(request));
        return;
    }

    if (requestUrl.pathname.includes('/assets/') || requestUrl.pathname.endsWith('/manifest.webmanifest')) {
        event.respondWith(fetchAndCache(request));
    }
});
