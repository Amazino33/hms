<x-filament-panels::page>
    <div class="space-y-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Warehouse</label>
            <p class="text-gray-900 dark:text-white">
                {{ \App\Models\WareHouse::find($selectedWarehouseId)?->name ?? 'Main Store' }}
                <span class="text-sm text-gray-500 dark:text-gray-400">— new stock can only be received here.</span>
            </p>
        </div>

        <div wire:init="load">
            @if (!$ready)
                @include('filament.widgets._deferred-placeholder')
            @else
                {{ $this->table }}
            @endif
        </div>
    </div>
</x-filament-panels::page>
