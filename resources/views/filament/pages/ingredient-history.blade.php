<x-filament-panels::page>
    @if (!$this->ingredient)
        <div class="bg-white dark:bg-gray-800 rounded-lg p-6 border border-gray-200 dark:border-gray-700">
            <p class="text-gray-600 dark:text-gray-400">No ingredient found for this id.</p>
        </div>
    @else
        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                <div class="flex items-center gap-3">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white">{{ $this->ingredient->name }}</h2>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">SKU: {{ $this->ingredient->sku ?? '—' }}</p>
            </div>

            {{-- Current stock per warehouse --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                <h3 class="px-4 py-3 font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700">Current Stock by Warehouse</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-left text-gray-500 dark:text-gray-400">
                            <tr><th class="px-4 py-2">Warehouse</th><th class="px-4 py-2 text-right">Quantity</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($this->inventoryByWarehouse as $item)
                                <tr>
                                    <td class="px-4 py-2">{{ $item->warehouse?->name ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right">{{ $item->quantity }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="px-4 py-3 text-gray-400">No inventory rows for this ingredient at any warehouse.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Transaction ledger --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                <h3 class="px-4 py-3 font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700">Transaction Ledger (last 100)</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-left text-gray-500 dark:text-gray-400">
                            <tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">Type</th><th class="px-4 py-2">Warehouse</th><th class="px-4 py-2 text-right">Qty</th><th class="px-4 py-2">Reference</th><th class="px-4 py-2">User</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($this->transactions as $txn)
                                <tr>
                                    <td class="px-4 py-2">{{ $txn->created_at?->venueTime()->format('M j, Y g:i A') ?? $txn->created_at?->format('M j, Y g:i A') ?? '—' }}</td>
                                    <td class="px-4 py-2"><span class="px-2 py-0.5 text-xs rounded bg-gray-100 dark:bg-gray-700">{{ $txn->type }}</span></td>
                                    <td class="px-4 py-2">{{ $txn->warehouse?->name ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right">{{ $txn->quantity }}</td>
                                    <td class="px-4 py-2 text-gray-500">{{ $txn->reference ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $txn->user?->name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-3 text-gray-400">No transactions recorded for this ingredient.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
