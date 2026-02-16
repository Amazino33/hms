<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $title ?? config('app.name') }}</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">

<!-- Development: Allow PWA on non-HTTPS for localhost -->
@if(app()->environment('local') && (request()->getHost() === 'localhost' || request()->getHost() === '127.0.0.1' || str_contains(request()->getHost(), '.test')))
<meta name="pwa-allow-insecure" content="true">
@endif

<!-- Debug: Check manifest link and fetch -->
<script>
    console.log('=== PWA DEBUG START ===');
    console.log('Current URL:', window.location.href);
    console.log('Is HTTPS:', window.location.protocol === 'https:');
    console.log('Is localhost:', window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1');

    // For development: Allow PWA on non-HTTPS if localhost
    if (window.location.protocol !== 'https:' &&
        window.location.hostname !== 'localhost' &&
        window.location.hostname !== '127.0.0.1') {
        console.warn('⚠️ PWA requires HTTPS in production. For development, use localhost or set up HTTPS.');
    }

    console.log('Manifest link element:', document.querySelector('link[rel="manifest"]'));

    if (document.querySelector('link[rel="manifest"]')) {
        console.log('Manifest link href:', document.querySelector('link[rel="manifest"]').href);
    }

    // Test manifest fetch with detailed logging
    fetch('/site.webmanifest', {
        method: 'GET',
        headers: {
            'Accept': 'application/manifest+json, application/json, */*'
        }
    })
    .then(response => {
        console.log('Manifest fetch status:', response.status);
        console.log('Manifest status text:', response.statusText);
        console.log('Manifest content-type:', response.headers.get('content-type'));
        console.log('All headers:', Object.fromEntries(response.headers.entries()));
        return response.text();
    })
    .then(text => {
        console.log('Manifest raw response:', text);
        try {
            const json = JSON.parse(text);
            console.log('✅ Manifest parsed successfully:', json);

            // Check if navigator can handle it
            if ('serviceWorker' in navigator) {
                console.log('✅ Service Worker supported');
            } else {
                console.log('❌ Service Worker NOT supported');
            }

            // Check PWA criteria
            if ('onbeforeinstallprompt' in window) {
                console.log('✅ Install prompt supported');
            } else {
                console.log('❌ Install prompt NOT supported');
            }

        } catch (e) {
            console.error('❌ Manifest JSON parse error:', e);
        }
    })
    .catch(error => {
        console.error('❌ Manifest fetch failed:', error);
    });

    console.log('=== PWA DEBUG END ===');
</script>

<meta name="theme-color" content="#1f2937">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="HMS">
<meta name="mobile-web-app-capable" content="yes">
<meta name="msapplication-TileColor" content="#1f2937">
<meta name="msapplication-config" content="/browserconfig.xml">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])

{{-- Inline Service Worker Registration - runs immediately on all pages --}}
<script>
(function() {
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('/sw.js')
                .then(function(registration) {
                    console.log('✅ ServiceWorker registered:', registration.scope);
                    // Check for updates every hour
                    setInterval(function() {
                        registration.update();
                    }, 60 * 60 * 1000);
                })
                .catch(function(err) {
                    console.error('❌ ServiceWorker registration failed:', err);
                });
        });
    } else {
        console.warn('⚠️ ServiceWorker not supported in this browser');
    }
})();
</script>

@fluxAppearance
