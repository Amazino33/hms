// Bumping this version is what actually busts stale cached JS/CSS in every
// visitor's browser — the activate handler below only deletes caches whose
// NAME differs from these constants, so a content change alone (without a
// version bump) leaves old cached assets served forever via cache-first,
// even after a fresh deploy. This was the real cause of a "notifications.js
// throws e is not a function" mismatch after the kiosk/staff layout fix.
const CACHE_NAME = 'hms-v2.4';
const STATIC_CACHE = 'hms-static-v2.4';
const DYNAMIC_CACHE = 'hms-dynamic-v2.4';

const STATIC_ASSETS = [
    '/offline.html',
    '/favicon.ico',
    '/apple-touch-icon.png',
    '/site.webmanifest',
    '/manifest.json',
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
    const authPaths = ['/login', '/logout', '/register', '/forgot-password', '/reset-password', '/admin/login', '/admin/logout'];

    event.waitUntil(
        caches.keys().then(names =>
            Promise.all(
                names.map(name =>
                    (name !== STATIC_CACHE && name !== DYNAMIC_CACHE)
                        ? caches.delete(name)
                        : null
                )
            )
        ).then(async () => {
            // Delete any stale auth pages that may be sitting in the dynamic cache
            const dynCache = await caches.open(DYNAMIC_CACHE);
            const keys = await dynCache.keys();
            await Promise.all(
                keys
                    .filter(req => authPaths.some(p => new URL(req.url).pathname.startsWith(p)))
                    .map(req => dynCache.delete(req))
            );
        }).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const { request } = event;
    if (request.method !== 'GET') return;
    // Ignore non-HTTP(S) requests (like chrome-extension://)
    if (!request.url.startsWith('http')) return;

    const url = new URL(request.url);

    // Static assets: cache-first
    if (url.pathname.match(/\.(css|js|png|jpg|jpeg|svg|ico|gif|webp|woff|woff2)$/)) {
        event.respondWith(
            caches.match(request).then(cached => 
                cached || fetch(request).then(response => {
                    if (response && response.status === 200 && !response.bodyUsed) {
                        const copy = response.clone();
                        caches.open(STATIC_CACHE).then(cache => {
                            try { cache.put(request, copy); } catch (e) {}
                        });
                    }
                    return response;
                })
            )
        );
        return;
    }

    // HTML/Navigation: NETWORK-FIRST (critical for Laravel)
    // Auth pages: ALWAYS go to network, NEVER read from or write to cache.
    // Serving a cached login page means a stale CSRF token → 419 Page Expired.
    const noCachePaths = ['/login', '/logout', '/register', '/forgot-password', '/reset-password', '/admin/login', '/admin/logout'];
    const isAuthPage = noCachePaths.some(p => url.pathname.startsWith(p));

    if (request.mode === 'navigate' || request.headers.get('accept')?.includes('text/html')) {
        if (isAuthPage) {
            // Completely bypass the service worker for auth pages — straight to network
            event.respondWith(fetch(request));
            return;
        }

        event.respondWith(
            fetch(request)
                .then(response => {
                    if (response.status === 200 && !response.bodyUsed) {
                        const copy = response.clone();
                        caches.open(DYNAMIC_CACHE).then(cache => {
                            try { cache.put(request, copy); } catch (e) {}
                        });
                    }
                    return response;
                })
                .catch(() =>
                    // Offline fallback: use cached page if available, else offline.html
                    // Auth pages already returned above so this never serves a stale login
                    caches.match(request)
                        .then(cached => cached || caches.match('/offline.html'))
                )
        );
        return;
    }

    event.respondWith(fetch(request));
});