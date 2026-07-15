<x-filament-panels::page>
    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">State</label>
            <select wire:model.live="state" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                <option value="both">Low + Sold Out</option>
                <option value="low">Low only</option>
                <option value="sold_out">Sold Out only</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Item Type</label>
            <select wire:model.live="itemType" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                <option value="">All</option>
                <option value="product">Product</option>
                <option value="ingredient">Ingredient</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Category</label>
            <select wire:model.live="category" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                <option value="">All</option>
                @foreach($this->categories() as $c)
                    <option value="{{ $c }}">{{ $c }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 mt-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                    <th class="p-2">Item</th><th class="p-2">Type</th><th class="p-2">Category</th><th class="p-2">Location</th>
                    <th class="p-2 text-right">Quantity</th><th class="p-2 text-right">Threshold</th>
                    <th class="p-2 text-right">Stock Value</th><th class="p-2">State</th>
                </tr>
            </thead>
            <tbody>
                @forelse($this->rows() as $row)
                    <tr class="border-b border-gray-100 dark:border-gray-700/50">
                        <td class="p-2">{{ $row['name'] }}</td>
                        <td class="p-2">{{ ucfirst($row['item_type']) }}</td>
                        <td class="p-2">{{ $row['category'] }}</td>
                        <td class="p-2">{{ $row['location'] }}</td>
                        <td class="p-2 text-right">{{ number_format($row['quantity'], 2) }}</td>
                        <td class="p-2 text-right">{{ $row['threshold'] }}</td>
                        <td class="p-2 text-right">₦{{ number_format($row['stock_value_at_cost'], 2) }}</td>
                        <td class="p-2">
                            @if($row['state'] === 'sold_out')
                                <span class="text-[10px] font-bold uppercase text-red-600 bg-red-50 dark:bg-red-900/30 px-2 py-0.5 rounded-full">Sold Out</span>
                            @else
                                <span class="text-[10px] font-bold uppercase text-amber-600 bg-amber-50 dark:bg-amber-900/30 px-2 py-0.5 rounded-full">Low</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="p-4 text-center text-gray-400">Nothing low or sold out.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
