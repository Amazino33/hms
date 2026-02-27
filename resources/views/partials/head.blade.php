<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $title ?? config('app.name') }}</title>

<link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
<link rel="manifest" href="{{ asset('site.webmanifest') }}">
<link rel="mask-icon" href="{{ asset('safari-pinned-tab.svg') }}" color="#1f2937">
<link rel="manifest" href="/manifest.json">

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

{{-- Navigation progress bar: shown immediately on every link click --}}
<style>
    #nprogress-bar {
        position: fixed;
        top: 0;
        left: 0;
        width: 0%;
        height: 3px;
        background: #f59e0b;
        z-index: 99999;
        transition: width 0.1s ease;
        border-radius: 0 2px 2px 0;
        box-shadow: 0 0 8px rgba(245, 158, 11, 0.6);
        pointer-events: none;
    }
</style>
<div id="nprogress-bar"></div>

{{-- Livewire wire:navigate progress bar + hover prefetch --}}
<script>
    // Show amber progress bar immediately on any link click (wire:navigate SPA nav)
    document.addEventListener('livewire:navigate', () => {
        const bar = document.getElementById('nprogress-bar');
        if (bar) { bar.style.width = '70%'; bar.style.opacity = '1'; }
    });
    document.addEventListener('livewire:navigated', () => {
        const bar = document.getElementById('nprogress-bar');
        if (bar) {
            bar.style.width = '100%';
            setTimeout(() => { bar.style.opacity = '0'; bar.style.width = '0%'; }, 200);
        }
    });

    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js');
            });
    }
</script>
</script>

@fluxAppearance