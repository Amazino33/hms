const CACHE_NAME = 'hms-v2.2';
const STATIC_CACHE = 'hms-static-v2.2';
const DYNAMIC_CACHE = 'hms-dynamic-v2.2';

const STATIC_ASSETS = [
    '/offline.html',
    '/favicon.ico',
    '/apple-touch-icon.png',
    '/site.webmanifest',
    '/favicon.svg'
];

self.addEventListener('install', event => {
    event.waitUntil((async () => {
        const staticCache = await caches.open(STATIC_CACHE);
        for (const url of STATIC_ASSETS) {
            try {
                const response = await fetch(url);
                if (response && response.ok) {
                    await staticCache.put(url, response.clone());
                }
            } catch (err) {
                console.warn('Failed to cache:', url);
            }
        }
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(names => 
            Promise.all(
                names.map(name => 
                    (name !== STATIC_CACHE && name !== DYNAMIC_CACHE) 
                        ? caches.delete(name) 
                        : null
                )
            )
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const { request } = event;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Static assets: cache-first
    if (url.pathname.match(/\.(css|js|png|jpg|jpeg|svg|ico|gif|webp|woff|woff2)$/)) {
        event.respondWith(
            caches.match(request).then(cached => 
                cached || fetch(request).then(response => {
                    if (response.status === 200) {
                        caches.open(STATIC_CACHE).then(cache => 
                            cache.put(request, response.clone())
                        );
                    }
                    return response;
                })
            )
        );
        return;
    }

    // HTML/Navigation: NETWORK-FIRST (critical for Laravel)
    if (request.mode === 'navigate' || request.headers.get('accept')?.includes('text/html')) {
        event.respondWith(
            fetch(request)
                .then(response => {
                    if (response.status === 200) {
                        caches.open(DYNAMIC_CACHE).then(cache => 
                            cache.put(request, response.clone())
                        );
                    }
                    return response;
                })
                .catch(() => 
                    caches.match(request)
                        .then(cached => cached || caches.match('/offline.html'))
                )
        );
        return;
    }

    event.respondWith(fetch(request));
});