<x-filament-panels::page>
    <div class="space-y-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Warehouse</label>
            <select wire:model="warehouseId" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                @foreach($this->warehouses() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>

            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mt-4 mb-2">
                Paste "Product Name, Quantity" — one per line
            </label>
            <textarea wire:model="pasteData" rows="12"
                placeholder="Andre wine, 2&#10;4th street, 3&#10;Amstel, 11"
                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white font-mono text-sm"></textarea>

            <button wire:click="preview" class="mt-4 px-4 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold">
                Preview
            </button>
        </div>

        @if($previewed)
            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                <h3 class="font-bold text-gray-900 dark:text-white mb-3">
                    Will set ({{ count($matched) }})
                </h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 dark:text-gray-400">
                                <th class="py-1 pr-4">Product</th>
                                <th class="py-1 pr-4">Current</th>
                                <th class="py-1 pr-4">New</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($matched as $row)
                                <tr class="border-t border-gray-100 dark:border-gray-700">
                                    <td class="py-1 pr-4 text-gray-900 dark:text-white">{{ $row['name'] }}</td>
                                    <td class="py-1 pr-4 text-gray-500">{{ $row['old_qty'] }}</td>
                                    <td class="py-1 pr-4 font-bold {{ $row['new_qty'] == $row['old_qty'] ? 'text-gray-400' : 'text-emerald-600' }}">
                                        {{ $row['new_qty'] }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @if(count($zeroedOut) > 0)
                <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-4 border border-red-200 dark:border-red-800">
                    <h3 class="font-bold text-red-700 dark:text-red-400 mb-3">
                        Will zero out ({{ count($zeroedOut) }}) — currently stocked, not in your list
                    </h3>
                    <ul class="text-sm space-y-1">
                        @foreach($zeroedOut as $row)
                            <li class="text-red-800 dark:text-red-300">{{ $row['name'] }} — currently {{ $row['old_qty'] }} → 0</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if(count($unmatched) > 0)
                <div class="bg-amber-50 dark:bg-amber-900/20 rounded-lg p-4 border border-amber-200 dark:border-amber-800">
                    <h3 class="font-bold text-amber-700 dark:text-amber-400 mb-3">
                        Couldn't match ({{ count($unmatched) }}) — check product names, these won't be touched
                    </h3>
                    <ul class="text-sm space-y-1 font-mono">
                        @foreach($unmatched as $line)
                            <li class="text-amber-800 dark:text-amber-300">{{ $line }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <button wire:click="apply" wire:confirm="Apply these changes to inventory? This can't be undone with a click."
                class="px-6 py-3 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold">
                Apply Changes
            </button>
        @endif
    </div>
</x-filament-panels::page>
