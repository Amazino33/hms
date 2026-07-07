<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>@yield('title', 'Kiosk')</title>
    @vite(['resources/css/app.css'])
    {{-- Notification::make()->send() toasts are Filament's, not a generic
         Livewire one — without this, <livewire:notifications /> renders
         but its Alpine component (notificationComponent) is never
         registered, so every notification silently fails to display
         instead of erroring loudly. This bit us in production: a real
         "you must start a shift" rejection looked like the Order button
         did nothing at all. --}}
    @filamentStyles
</head>
<body class="bg-gray-900">
    @yield('content')

    <livewire:notifications />
    {{-- @filamentScripts(withCore: true) already bundles and boots
         Livewire's core JS — Filament's own panel layout never also calls
         @livewireScripts alongside it. Having both loaded Livewire twice,
         and the second init stomped on the first, breaking Filament's
         notification component with "e is not a function" the moment it
         tried to render a toast. --}}
    @filamentScripts(withCore: true)
</body>
</html>
