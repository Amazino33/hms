@props([
    'show',           // Alpine boolean expression string, e.g. 'showReturnModal' or 'lineEditOpen'
    'title' => null,
])
{{--
    Standard container for secondary detail (line edit, note entry, numeric
    pad is its own thing — see mobile.stepper — this is for everything else):
    slides up from the bottom, drag-handle, swipe-down or scrim-tap to
    dismiss. x-teleport so it always renders at <body> regardless of where
    it's declared, keeping it above any ancestor's overflow/stacking context.
--}}
<template x-teleport="body">
    <div x-show="{{ $show }}" x-cloak class="fixed inset-0 bg-black/40 z-[80]" @click="{{ $show }} = false" x-transition.opacity></div>
</template>
<template x-teleport="body">
    <div x-show="{{ $show }}" x-cloak
        x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
        x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
        class="fixed inset-x-0 bottom-0 z-[81] bg-white dark:bg-gray-900 rounded-t-2xl shadow-2xl border-t border-gray-200 dark:border-gray-700 max-h-[85vh] overflow-y-auto w-full max-w-md mx-auto"
        style="padding-bottom: env(safe-area-inset-bottom);"
        x-data="{ _ty: 0, _tdy: 0 }"
        @touchstart="_ty = $event.touches[0].clientY"
        @touchmove="_tdy = $event.touches[0].clientY - _ty"
        @touchend="if (_tdy > 80) { {{ $show }} = false }; _tdy = 0">
        <div class="w-10 h-1.5 bg-gray-300 dark:bg-gray-600 rounded-full mx-auto mt-3 mb-2"></div>
        @if($title)
            <h3 class="text-lg font-bold text-gray-900 dark:text-white text-center px-5 mb-2">{{ $title }}</h3>
        @endif
        <div class="px-5 pb-5">
            {{ $slot }}
        </div>
    </div>
</template>
