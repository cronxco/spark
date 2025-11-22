/**
 * Spark Service Worker
 * Handles push notifications and offline caching
 */

const CACHE_VERSION = 'v1';
const CACHE_NAME = `spark-cache-${CACHE_VERSION}`;

// Assets to cache for offline use
const PRECACHE_ASSETS = [
    '/apple-touch-icon.png',
    '/favicon.ico',
    '/favicon.svg',
];

// Install event - cache assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(PRECACHE_ASSETS))
            .then(() => self.skipWaiting())
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames
                        .filter((name) => name.startsWith('spark-cache-') && name !== CACHE_NAME)
                        .map((name) => caches.delete(name))
                );
            })
            .then(() => self.clients.claim())
    );
});

// Push event - handle incoming push notifications
self.addEventListener('push', (event) => {
    if (!event.data) {
        console.log('Push event received but no data');
        return;
    }

    let data;
    try {
        data = event.data.json();
    } catch (e) {
        console.error('Failed to parse push data:', e);
        data = {
            title: 'Spark',
            body: event.data.text(),
        };
    }

    const options = {
        body: data.body || data.message || '',
        icon: data.icon || '/apple-touch-icon.png',
        badge: data.badge || '/favicon.ico',
        tag: data.tag || 'spark-notification',
        data: data.data || {},
        vibrate: [100, 50, 100],
        requireInteraction: data.requireInteraction || false,
        actions: data.actions || [
            { action: 'open', title: 'Open' },
            { action: 'dismiss', title: 'Dismiss' }
        ],
        timestamp: data.timestamp || Date.now(),
    };

    // If there's an image, add it
    if (data.image) {
        options.image = data.image;
    }

    event.waitUntil(
        self.registration.showNotification(data.title || 'Spark', options)
    );
});

// Notification click event - handle user interaction
self.addEventListener('notificationclick', (event) => {
    const notification = event.notification;
    const action = event.action;
    const data = notification.data || {};

    notification.close();

    if (action === 'dismiss') {
        return;
    }

    // Default action or 'open' action - navigate to URL
    const urlToOpen = data.url || '/dashboard';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((windowClients) => {
                // Check if there's already a window open
                for (const client of windowClients) {
                    if (client.url.includes(self.location.origin) && 'focus' in client) {
                        client.focus();
                        if ('navigate' in client) {
                            return client.navigate(urlToOpen);
                        }
                        return client;
                    }
                }
                // No existing window, open a new one
                if (clients.openWindow) {
                    return clients.openWindow(urlToOpen);
                }
            })
    );
});

// Notification close event - for analytics
self.addEventListener('notificationclose', (event) => {
    const notification = event.notification;
    const data = notification.data || {};

    // Optionally track notification dismissals
    if (data.notification_id) {
        // Could send analytics here if needed
        console.log('Notification closed:', data.notification_id);
    }
});

// Fetch event - network-first strategy with cache fallback
self.addEventListener('fetch', (event) => {
    // Only cache GET requests
    if (event.request.method !== 'GET') {
        return;
    }

    // Skip cross-origin requests
    if (!event.request.url.startsWith(self.location.origin)) {
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                // Don't cache non-successful responses
                if (!response || response.status !== 200 || response.type !== 'basic') {
                    return response;
                }

                // Clone the response for caching
                const responseToCache = response.clone();
                caches.open(CACHE_NAME)
                    .then((cache) => {
                        cache.put(event.request, responseToCache);
                    });

                return response;
            })
            .catch(() => {
                // Network failed, try cache
                return caches.match(event.request);
            })
    );
});

// Handle push subscription change
self.addEventListener('pushsubscriptionchange', (event) => {
    event.waitUntil(
        self.registration.pushManager.subscribe(event.oldSubscription.options)
            .then((subscription) => {
                // Re-register the new subscription with the server
                return fetch('/api/push/subscribe', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(subscription.toJSON()),
                    credentials: 'same-origin',
                });
            })
    );
});
