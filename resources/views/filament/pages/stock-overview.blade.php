<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-2 mb-4">
        <button type="button" wire:click="setViewMode('products')"
            class="px-4 py-2 rounded-lg text-sm font-bold border-2 transition-colors {{ $viewMode === 'products'
                ? 'border-primary-500 bg-primary-500 text-white'
                : 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300' }}">
            Products
        </button>
        <button type="button" wire:click="setViewMode('ingredients')"
            class="px-4 py-2 rounded-lg text-sm font-bold border-2 transition-colors {{ $viewMode === 'ingredients'
                ? 'border-primary-500 bg-primary-500 text-white'
                : 'border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300' }}">
            Ingredients
        </button>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
