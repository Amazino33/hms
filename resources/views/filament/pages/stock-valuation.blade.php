<x-filament-panels::page>
    {{-- 1. Show the Stats Widgets --}}
    {{-- @if ($headerWidgets = $this->getVisibleHeaderWidgets())
        <x-filament-widgets::widgets
            :widgets="$headerWidgets"
            :columns="$this->getHeaderWidgetsColumns()"
        />
    @endif --}}

    {{-- 2. Show the Table --}}
    {{ $this->table }}
</x-filament-panels::page>