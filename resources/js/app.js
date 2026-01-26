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
                }
            })
            .catch(error => {
                console.log('Service Worker registration failed:', error);
            });
    });
}

// Function to manually trigger install prompt
window.installPWA = function() {
    if (deferredPrompt) {
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then((choiceResult) => {
            if (choiceResult.outcome === 'accepted') {
                console.log('User accepted the install prompt');
            } else {
                console.log('User dismissed the install prompt');
            }
            deferredPrompt = null;
        });
    } else {
        console.log('Install prompt not available');
    }
};
