/**
 * Spark Push Notifications
 * Handles service worker registration and push subscription management
 */

class SparkPushNotifications {
    constructor() {
        this.swRegistration = null;
        this.isSubscribed = false;
        this.vapidPublicKey = null;
    }

    /**
     * Initialize push notifications
     */
    async init() {
        // Check if push notifications are supported
        if (!this.isSupported()) {
            console.log('Push notifications are not supported');
            return false;
        }

        try {
            // Register service worker
            this.swRegistration = await this.registerServiceWorker();

            // Get VAPID public key
            this.vapidPublicKey = await this.getVapidPublicKey();

            // Check current subscription status
            await this.updateSubscriptionStatus();

            // Dispatch custom event for UI to react
            window.dispatchEvent(new CustomEvent('push-initialized', {
                detail: {
                    supported: true,
                    subscribed: this.isSubscribed,
                    permission: Notification.permission,
                }
            }));

            return true;
        } catch (error) {
            console.error('Failed to initialize push notifications:', error);
            return false;
        }
    }

    /**
     * Check if push notifications are supported
     */
    isSupported() {
        return 'serviceWorker' in navigator &&
               'PushManager' in window &&
               'Notification' in window;
    }

    /**
     * Check if running as installed PWA
     */
    isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches ||
               window.navigator.standalone === true ||
               document.referrer.includes('android-app://');
    }

    /**
     * Check if iOS device
     */
    isIOS() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    }

    /**
     * Check if iOS Safari (not standalone PWA)
     */
    isIOSSafari() {
        return this.isIOS() && !this.isStandalone();
    }

    /**
     * Register the service worker
     */
    async registerServiceWorker() {
        try {
            const registration = await navigator.serviceWorker.register('/sw.js', {
                scope: '/'
            });

            console.log('Service Worker registered:', registration.scope);

            // Handle updates
            registration.addEventListener('updatefound', () => {
                const newWorker = registration.installing;
                newWorker.addEventListener('statechange', () => {
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        // New version available
                        window.dispatchEvent(new CustomEvent('sw-update-available'));
                    }
                });
            });

            return registration;
        } catch (error) {
            console.error('Service Worker registration failed:', error);
            throw error;
        }
    }

    /**
     * Get VAPID public key from server
     */
    async getVapidPublicKey() {
        try {
            const response = await fetch('/api/push/vapid-public-key');
            const data = await response.json();
            return data.publicKey;
        } catch (error) {
            console.error('Failed to get VAPID public key:', error);
            throw error;
        }
    }

    /**
     * Update the subscription status
     */
    async updateSubscriptionStatus() {
        if (!this.swRegistration) {
            this.isSubscribed = false;
            return;
        }

        const subscription = await this.swRegistration.pushManager.getSubscription();
        this.isSubscribed = subscription !== null;

        return this.isSubscribed;
    }

    /**
     * Request notification permission
     */
    async requestPermission() {
        if (Notification.permission === 'granted') {
            return true;
        }

        if (Notification.permission === 'denied') {
            return false;
        }

        const permission = await Notification.requestPermission();
        return permission === 'granted';
    }

    /**
     * Subscribe to push notifications
     */
    async subscribe() {
        if (!this.swRegistration || !this.vapidPublicKey) {
            throw new Error('Push notifications not initialized');
        }

        // Request permission first
        const hasPermission = await this.requestPermission();
        if (!hasPermission) {
            throw new Error('Notification permission denied');
        }

        try {
            // Convert VAPID key to Uint8Array
            const applicationServerKey = this.urlBase64ToUint8Array(this.vapidPublicKey);

            // Subscribe to push
            const subscription = await this.swRegistration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: applicationServerKey,
            });

            // Send subscription to server
            await this.sendSubscriptionToServer(subscription);

            this.isSubscribed = true;

            window.dispatchEvent(new CustomEvent('push-subscribed', {
                detail: { subscription }
            }));

            return subscription;
        } catch (error) {
            console.error('Failed to subscribe to push:', error);
            throw error;
        }
    }

    /**
     * Unsubscribe from push notifications
     */
    async unsubscribe() {
        if (!this.swRegistration) {
            return;
        }

        try {
            const subscription = await this.swRegistration.pushManager.getSubscription();

            if (subscription) {
                // Remove from server first
                await this.removeSubscriptionFromServer(subscription);

                // Then unsubscribe locally
                await subscription.unsubscribe();
            }

            this.isSubscribed = false;

            window.dispatchEvent(new CustomEvent('push-unsubscribed'));

            return true;
        } catch (error) {
            console.error('Failed to unsubscribe from push:', error);
            throw error;
        }
    }

    /**
     * Send subscription to server
     */
    async sendSubscriptionToServer(subscription) {
        const response = await fetch('/api/push/subscribe', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': this.getCsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(subscription.toJSON()),
        });

        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.message || 'Failed to save subscription');
        }

        return response.json();
    }

    /**
     * Remove subscription from server
     */
    async removeSubscriptionFromServer(subscription) {
        const response = await fetch('/api/push/unsubscribe', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': this.getCsrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ endpoint: subscription.endpoint }),
        });

        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.message || 'Failed to remove subscription');
        }

        return response.json();
    }

    /**
     * Get CSRF token from meta tag
     */
    getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /**
     * Convert URL-safe base64 to Uint8Array
     */
    urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');

        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);

        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }

        return outputArray;
    }

    /**
     * Get current notification permission status
     */
    getPermissionStatus() {
        if (!('Notification' in window)) {
            return 'unsupported';
        }
        return Notification.permission;
    }

    /**
     * Check if we can request permission (not denied and not already granted)
     */
    canRequestPermission() {
        return this.getPermissionStatus() === 'default';
    }
}

// Create global instance
window.SparkPush = new SparkPushNotifications();

// Auto-initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.SparkPush.init();
});

// Register Alpine.js component for push notifications UI
document.addEventListener('alpine:init', () => {
    Alpine.data('pushNotifications', () => ({
        supported: false,
        subscribed: false,
        permission: 'default',
        isiOSSafari: false,
        loading: false,

        async init() {
            // Check support
            this.supported = 'serviceWorker' in navigator &&
                             'PushManager' in window &&
                             'Notification' in window;

            if (!this.supported) return;

            // Check if iOS Safari (not standalone)
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
            const isStandalone = window.matchMedia('(display-mode: standalone)').matches ||
                                window.navigator.standalone === true;
            this.isiOSSafari = isIOS && !isStandalone;

            // Get permission status
            this.permission = Notification.permission;

            // Check subscription status
            if (navigator.serviceWorker.controller) {
                await this.checkSubscription();
            } else {
                navigator.serviceWorker.ready.then(() => this.checkSubscription());
            }

            // Listen for push events
            window.addEventListener('push-subscribed', () => {
                this.subscribed = true;
                if (this.$wire) this.$wire.$refresh();
            });

            window.addEventListener('push-unsubscribed', () => {
                this.subscribed = false;
                if (this.$wire) this.$wire.$refresh();
            });
        },

        async checkSubscription() {
            try {
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.getSubscription();
                this.subscribed = subscription !== null;
            } catch (e) {
                console.error('Error checking subscription:', e);
            }
        },

        async subscribe() {
            if (!window.SparkPush) {
                console.error('SparkPush not initialized');
                return;
            }

            this.loading = true;
            try {
                await window.SparkPush.subscribe();
                this.subscribed = true;
                this.permission = Notification.permission;
                if (this.$wire) this.$wire.$refresh();
            } catch (e) {
                console.error('Subscribe error:', e);
                if (e.message.includes('permission')) {
                    this.permission = 'denied';
                }
            } finally {
                this.loading = false;
            }
        },

        async unsubscribe() {
            if (!window.SparkPush) {
                console.error('SparkPush not initialized');
                return;
            }

            this.loading = true;
            try {
                await window.SparkPush.unsubscribe();
                this.subscribed = false;
                if (this.$wire) this.$wire.$refresh();
            } catch (e) {
                console.error('Unsubscribe error:', e);
            } finally {
                this.loading = false;
            }
        }
    }));
});

// Export for module usage
export default SparkPushNotifications;
