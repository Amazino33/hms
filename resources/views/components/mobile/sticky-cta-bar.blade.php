@props(['retrying' => null])
{{--
    Fixed bottom action bar for every multi-step flow: safe-area-aware,
    optional one-line context readout, optional retry banner, and the
    caller's own button in the default slot — button wiring (wire:click vs
    an Alpine flush-then-open-sheet pattern vs a $wire.call().then() chain)
    varies too much per surface to standardize here; this component owns
    layout/positioning/safe-area only, not the action logic.

    Destructive/secondary actions never belong in this bar — voids, cancels,
    and back actions stay elsewhere (top-left back, inside a review sheet).

    Hides itself while a mobile.numeric-pad sheet is open (spec: the pad
    must fully replace it, not overlap it) — a counter, not a boolean,
    since it must stay hidden if a second pad opens before the first
    closes. The pad components are the only thing that dispatch these two
    window events; nothing else needs to know this bar exists.
--}}
<div x-data="{ hmsOpenPads: 0 }"
    x-init="
        window.addEventListener('hms-pad-open', () => hmsOpenPads++);
        window.addEventListener('hms-pad-close', () => hmsOpenPads = Math.max(0, hmsOpenPads - 1));
    "
    x-show="hmsOpenPads === 0"
    {{ $attributes->merge(['class' => 'fixed inset-x-0 bottom-0 z-40 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-700 px-4 pt-3']) }}
    style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom));">
    @if($retrying)
        <div x-show="{{ $retrying }}" x-cloak
            class="mb-2 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-700 px-3 py-2 text-sm text-red-700 dark:text-red-300 flex items-center justify-between gap-2">
            <span>Could not save — check your connection.</span>
            {{ $retryAction ?? '' }}
        </div>
    @endif
    @isset($context)
        <div class="text-xs font-bold text-gray-500 dark:text-gray-400 mb-1.5 text-center truncate">
            {{ $context }}
        </div>
    @endisset
    {{ $slot }}
</div>
