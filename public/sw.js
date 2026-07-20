// Bumping this version is what actually busts stale cached JS/CSS in every
// visitor's browser — the activate handler below only deletes caches whose
// NAME differs from these constants, so a content change alone (without a
// version bump) leaves old cached assets served forever via cache-first,
// even after a fresh deploy. This was the real cause of a "notifications.js
// throws e is not a function" mismatch after the kiosk/staff layout fix.
const CACHE_NAME = 'hms-v2.6';
const STATIC_CACHE = 'hms-static-v2.6';

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
    // No dynamic HTML cache exists to prune anymore (see the fetch handler
    // below) — every cache whose name doesn't match this version's
    // STATIC_CACHE gets dropped, which also flushes any page HTML an
    // older service worker version had cached (including hms-dynamic-*
    // from before this fix).
    event.waitUntil(
        caches.keys()
            .then(names => Promise.all(
                names.map(name => name !== STATIC_CACHE ? caches.delete(name) : null)
            ))
            .then(() => self.clients.claim())
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

    // HTML/Navigation: NETWORK-FIRST, ALWAYS (critical for Laravel).
    // Every page in this app past the idle screen is authenticated and
    // carries a CSRF token + Livewire snapshot tied to the exact session
    // that rendered it. Falling back to a cached copy of ANY such page on
    // a network hiccup — not just /login — serves a stale token, which
    // the server correctly rejects with 419 on the next action. A kiosk
    // device is shared across many staff through a shift, so a stale
    // cached page could even carry a PREVIOUS staff member's session
    // state. The only safe fallback on a real network failure is the
    // static, session-free /offline.html — never caches.match(request).
    // Dynamic pages are therefore never written to cache either, since
    // nothing ever reads them back.
    if (request.mode === 'navigate' || request.headers.get('accept')?.includes('text/html')) {
        event.respondWith(
            fetch(request).catch(() => caches.match('/offline.html'))
        );
        return;
    }

    event.respondWith(fetch(request).catch(() => Response.error()));
});