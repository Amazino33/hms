// Enhanced Service Worker for HMS PWA
const CACHE_NAME = 'hms-v2.0';
const STATIC_CACHE = 'hms-static-v2.0';
const DYNAMIC_CACHE = 'hms-dynamic-v2.0';

// Resources to cache immediately
const STATIC_ASSETS = [
    '/',
    '/dashboard',
    '/pos',
    '/offline',
    '/offline.html',
    '/css/app.css',
    '/js/app.js',
    '/favicon.ico',
    '/apple-touch-icon.png',
    '/site.webmanifest',
    '/favicon.svg'
];

// API endpoints to cache
const API_ENDPOINTS = [
    '/api/user',
    '/api/dashboard-data'
];

// Install event - cache essential resources
self.addEventListener('install', event => {
    console.log('Service Worker: Installing...');
    event.waitUntil(
        Promise.all([
            caches.open(STATIC_CACHE).then(cache => {
                console.log('Service Worker: Caching static assets');
                return cache.addAll(STATIC_ASSETS);
            }),
            caches.open(DYNAMIC_CACHE)
        ]).then(() => {
            return self.skipWaiting();
        })
    );
});

// Activate event - clean up old caches and take control
self.addEventListener('activate', event => {
    console.log('Service Worker: Activating...');
    event.waitUntil(
        Promise.all([
            // Clean up old caches
            caches.keys().then(cacheNames => {
                return Promise.all(
                    cacheNames.map(cacheName => {
                        if (cacheName !== STATIC_CACHE && cacheName !== DYNAMIC_CACHE) {
                            console.log('Service Worker: Deleting old cache:', cacheName);
                            return caches.delete(cacheName);
                        }
                    })
                );
            }),
            // Take control of all clients
            self.clients.claim()
        ])
    );
});

// Fetch event - serve from cache or network
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip non-GET requests
    if (request.method !== 'GET') return;

    // Handle API requests
    if (url.pathname.startsWith('/api/')) {
        event.respondWith(
            caches.open(DYNAMIC_CACHE).then(cache => {
                return fetch(request)
                    .then(response => {
                        // Cache successful responses
                        if (response.status === 200) {
                            cache.put(request, response.clone());
                        }
                        return response;
                    })
                    .catch(() => {
                        // Return cached version if available
                        return cache.match(request);
                    });
            })
        );
        return;
    }

    // Handle static assets
    if (STATIC_ASSETS.includes(url.pathname) || url.pathname.match(/\.(css|js|png|jpg|jpeg|svg|ico|woff|woff2)$/)) {
        event.respondWith(
            caches.match(request).then(cachedResponse => {
                return cachedResponse || fetch(request).then(response => {
                    // Cache the response
                    const responseClone = response.clone();
                    caches.open(STATIC_CACHE).then(cache => {
                        cache.put(request, responseClone);
                    });
                    return response;
                });
            })
        );
        return;
    }

    // Default strategy: Network first, fallback to cache
    event.respondWith(
        fetch(request)
            .then(response => {
                // Cache successful HTML responses
                if (response.status === 200 && request.headers.get('accept').includes('text/html')) {
                    const responseClone = response.clone();
                    caches.open(DYNAMIC_CACHE).then(cache => {
                        cache.put(request, responseClone);
                    });
                }
                return response;
            })
            .catch(() => {
                // Return cached version or offline page
                return caches.match(request).then(cachedResponse => {
                    if (cachedResponse) {
                        return cachedResponse;
                    }
                    // Return offline page for HTML requests
                    if (request.headers.get('accept').includes('text/html')) {
                        return caches.match('/offline.html');
                    }
                    return new Response('', { status: 404 });
                });
            })
    );
});

// Background sync for offline actions (if needed in future)
self.addEventListener('sync', event => {
    console.log('Service Worker: Background sync triggered');
    if (event.tag === 'background-sync') {
        event.waitUntil(doBackgroundSync());
    }
});

async function doBackgroundSync() {
    // Implement background sync logic here if needed
    console.log('Performing background sync...');
}

// Push notifications (if needed in future)
self.addEventListener('push', event => {
    console.log('Service Worker: Push received');
    if (event.data) {
        const data = event.data.json();
        const options = {
            body: data.body,
            icon: '/apple-touch-icon.png',
            badge: '/favicon.ico',
            vibrate: [100, 50, 100],
            data: data.data
        };

        event.waitUntil(
            self.registration.showNotification(data.title, options)
        );
    }
});

// Notification click handler
self.addEventListener('notificationclick', event => {
    console.log('Service Worker: Notification clicked');
    event.notification.close();

    event.waitUntil(
        clients.openWindow(event.notification.data.url || '/')
    );
});