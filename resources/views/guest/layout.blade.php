{{-- Guest pages (Phase 1B placeholders). Deliberately self-contained: no
     Vite bundle, no Livewire, no web fonts — a weak 3G connection gets one
     small HTML response and nothing else to wait for. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#121214">
    <title>@yield('title') · {{ $venue }}</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               background: #121214; color: #F5F5F5; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
               padding: 24px 16px; text-align: center; }
        .venue { font-size: 12px; font-weight: 600; letter-spacing: .32em; text-transform: uppercase; color: #E04355; margin: 0 0 14px; }
        h1 { font-size: 28px; margin: 0 0 8px; }
        p { font-size: 16px; color: #A1A1AA; margin: 0; line-height: 1.5; }
    </style>
</head>
<body>
    <main>
        <p class="venue">{{ $venue }}</p>
        @yield('content')
    </main>
</body>
</html>
