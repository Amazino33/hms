import 'instant.page';

// Enhanced PWA Service Worker Registration with Install Prompt
let deferredPrompt;

window.addEventListener('beforeinstallprompt', (e) => {
    // Prevent the mini-infobar from appearing on mobile
    e.preventDefault();
    // Stash the event so it can be triggered later
    deferredPrompt = e;

    // Show custom install button or dispatch event
    window.dispatchEvent(new CustomEvent('pwa-installable'));
});

window.addEventListener('appinstalled', (evt) => {
    console.log('PWA was installed successfully');
    // Clear the deferred prompt
    deferredPrompt = null;

    // Dispatch success event
    window.dispatchEvent(new CustomEvent('pwa-installed'));
});

// Register Service Worker for PWA functionality
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then(registration => {
                console.log('Service Worker registered successfully:', registration.scope);

                // Check if already installed
                if (window.matchMedia('(display-mode: standalone)').matches) {
                    console.log('App is already installed');
                    window.dispatchEvent(new CustomEvent('pwa-already-installed'));
                }

                // Handle service worker updates
                registration.addEventListener('updatefound', () => {
                    const newWorker = registration.installing;
                    if (newWorker) {
                        newWorker.addEventListener('statechange', () => {
                            if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                // New content is available, show update prompt
                                window.dispatchEvent(new CustomEvent('pwa-update-available', {
                                    detail: { registration }
                                }));
                            }
                        });
                    }
                });
            })
            .catch(error => {
                console.log('Service Worker registration failed:', error);
                window.dispatchEvent(new CustomEvent('pwa-sw-error', {
                    detail: { error }
                }));
            });
    });
}

// Enhanced function to manually trigger install prompt with promise support
window.installPWA = function() {
    return new Promise((resolve, reject) => {
        if (!deferredPrompt) {
            reject(new Error('Install prompt not available. The app may already be installed or your browser doesn\'t support PWA installation.'));
            return;
        }

        // Add event listener for successful installation
        const handleInstalled = () => {
            window.removeEventListener('appinstalled', handleInstalled);
            resolve();
        };
        window.addEventListener('appinstalled', handleInstalled);

        // Show the install prompt
        deferredPrompt.prompt();

        // Handle user's choice
        deferredPrompt.userChoice.then((choiceResult) => {
            if (choiceResult.outcome === 'accepted') {
                console.log('User accepted the install prompt');
                // Resolve will be called by the appinstalled event
            } else {
                console.log('User dismissed the install prompt');
                window.removeEventListener('appinstalled', handleInstalled);
                reject(new Error('User dismissed the install prompt'));
            }
            deferredPrompt = null;
        }).catch((error) => {
            console.error('Error during install prompt:', error);
            window.removeEventListener('appinstalled', handleInstalled);
            reject(error);
        });
    });
};

// Check PWA capabilities and requirements
window.checkPWASupport = function() {
    const checks = {
        serviceWorker: 'serviceWorker' in navigator,
        manifest: 'manifest' in document.createElement('link'),
        standalone: window.matchMedia('(display-mode: standalone)').matches,
        https: location.protocol === 'https:' || location.hostname === 'localhost',
        installPrompt: !!deferredPrompt
    };

    console.log('PWA Support Check:', checks);
    return checks;
};

// Auto-check PWA support on load
document.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => {
        window.checkPWASupport();
    }, 1000);
});

// Lightweight link prefetcher: preloads same-origin links marked with `data-prefetch`
(function () {
    const prefetchCache = new Set();
    let timer = null;

    function schedulePrefetch(href) {
        if (!href || prefetchCache.has(href)) return;
        timer = setTimeout(() => {
            // Warm server + network by fetching the URL (credentials included for session)
            fetch(href, { credentials: 'include' }).then(() => prefetchCache.add(href)).catch(() => {});
        }, 65); // short delay to avoid accidental hovers
    }

    function cancelPrefetch() {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    }

    document.addEventListener('mouseover', (e) => {
        const a = e.target.closest && e.target.closest('a[data-prefetch]');
        if (!a) return;
        const href = a.href;
        try {
            if (new URL(href).origin !== location.origin) return;
        } catch (err) {
            return;
        }
        schedulePrefetch(href);
    });

    document.addEventListener('mouseout', (e) => {
        if (e.target.closest && e.target.closest('a[data-prefetch]')) cancelPrefetch();
    });

    document.addEventListener('focusin', (e) => {
        const a = e.target.closest && e.target.closest('a[data-prefetch]');
        if (a) schedulePrefetch(a.href);
    });

    document.addEventListener('focusout', (e) => {
        if (e.target.closest && e.target.closest('a[data-prefetch]')) cancelPrefetch();
    });
})();
