<x-filament-panels::page>
    {{-- 1. Header widgets (already deferred where appropriate) --}}
    {{-- 2. Defer the heavy products table until after initial render via wire:init --}}
    <div wire:init="load">
        @if (!$ready)
            {{-- Placeholder while table data is loaded asynchronously --}}
            @include('filament.widgets._deferred-placeholder')
        @else
            {{ $this->table }}
        @endif
    </div>
</x-filament-panels::page>