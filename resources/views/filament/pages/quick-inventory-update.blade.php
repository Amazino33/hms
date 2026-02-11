<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Header Section -->
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-gray-800 dark:to-gray-900 rounded-lg p-6 border border-blue-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Quick Inventory Update</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Add stock to your warehouse based on purchases</p>
                </div>
                <div class="text-5xl">📦</div>
            </div>
        </div>

        <!-- Warehouse Selector -->
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Select Warehouse</label>
            <select wire:model.live="selectedWarehouseId" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-blue-500">
                <option value="">-- Choose Warehouse --</option>
                @foreach(\App\Models\WareHouse::all() as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }} ({{ $warehouse->type }})</option>
                @endforeach
            </select>
        </div>

        <!-- Products Table -->
        {{ $this->table }}

        <!-- Quick Tips -->
        <div class="bg-amber-50 dark:bg-amber-900/20 rounded-lg p-4 border border-amber-200 dark:border-amber-800">
            <div class="flex gap-3">
                <div class="text-xl">💡</div>
                <div>
                    <h3 class="font-semibold text-amber-900 dark:text-amber-100">Quick Tips</h3>
                    <ul class="text-sm text-amber-800 dark:text-amber-200 mt-2 space-y-1">
                        <li>✓ Select a warehouse before adding stock</li>
                        <li>✓ Click "Add Stock" to record a purchase</li>
                        <li>✓ Reference numbers help track purchase sources</li>
                        <li>✓ Low stock items are highlighted in red</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>