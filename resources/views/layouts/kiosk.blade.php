<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>@yield('title', 'Kiosk')</title>
    @vite(['resources/css/app.css'])
    @livewireStyles
</head>
<body class="bg-gray-900">
    @yield('content')

    <livewire:notifications />
    @livewireScripts
</body>
</html>
