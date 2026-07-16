<x-filament-panels::page>
    <div class="space-y-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700"
            x-data="{ warehouseId: @entangle('warehouseId') }">
            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Warehouse</label>
            <x-mobile.chip-select model="warehouseId" :options="$this->warehouses()" />

            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mt-4 mb-2">
                Paste "Product Name, Quantity" — one per line
            </label>
            <textarea wire:model="pasteData" rows="12"
                placeholder="Andre wine, 2&#10;4th street, 3&#10;Amstel, 11"
                class="w-full px-4 py-3 min-h-[48px] border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white font-mono text-sm"></textarea>

            <button wire:click="preview" class="mt-4 w-full min-h-[48px] px-4 py-3 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold touch-manipulation">
                Preview
            </button>
        </div>

        @if($previewed)
            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                <h3 class="font-bold text-gray-900 dark:text-white mb-3">
                    Will set ({{ count($matched) }})
                </h3>
                <div class="hidden md:block overflow-x-auto hms-table-scroll">
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
                <div class="md:hidden space-y-2 max-h-80 overflow-y-auto">
                    @foreach($matched as $row)
                        <div class="flex items-center justify-between gap-2 bg-gray-50 dark:bg-gray-900/50 rounded-lg p-3 border border-gray-100 dark:border-gray-700">
                            <span class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ $row['name'] }}</span>
                            <span class="shrink-0 text-sm font-mono">
                                <span class="text-gray-400">{{ $row['old_qty'] }}</span>
                                <span class="mx-1 text-gray-400">→</span>
                                <span class="font-bold {{ $row['new_qty'] == $row['old_qty'] ? 'text-gray-400' : 'text-emerald-600' }}">{{ $row['new_qty'] }}</span>
                            </span>
                        </div>
                    @endforeach
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

            <x-mobile.sticky-cta-bar>
                <x-slot:context>{{ count($matched) }} to set{{ count($zeroedOut) > 0 ? ' · ' . count($zeroedOut) . ' to zero out' : '' }}</x-slot:context>
                <button wire:click="apply" wire:confirm="Apply these changes to inventory? This can't be undone with a click." wire:loading.attr="disabled" wire:target="apply"
                    class="w-full min-h-[48px] py-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-lg font-bold touch-manipulation">
                    <span wire:loading.remove wire:target="apply">Apply Changes</span>
                    <span wire:loading wire:target="apply">Applying…</span>
                </button>
            </x-mobile.sticky-cta-bar>
        @endif
    </div>
</x-filament-panels::page>
