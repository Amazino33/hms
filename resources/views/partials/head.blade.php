<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $title ?? config('app.name') }}</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">

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
        top: 0; left: 0;
        width: 0%;
        height: 3px;
        background: #f59e0b;
        z-index: 99999;
        transition: width 0.1s ease;
        border-radius: 0 2px 2px 0;
        box-shadow: 0 0 8px rgba(245,158,11,0.6);
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
</script>

@fluxAppearance
