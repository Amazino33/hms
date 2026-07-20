<x-filament-panels::page>
    @if (!$this->product)
        <div class="bg-white dark:bg-gray-800 rounded-lg p-6 border border-gray-200 dark:border-gray-700">
            <p class="text-gray-600 dark:text-gray-400">No product found for this id.</p>
        </div>
    @else
        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                <div class="flex items-center gap-3">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white">{{ $this->product->name }}</h2>
                    @if ($this->product->trashed())
                        <span class="px-2 py-0.5 text-xs font-semibold rounded bg-danger-100 text-danger-700 dark:bg-danger-900 dark:text-danger-200">
                            Deleted {{ $this->product->deleted_at->diffForHumans() }}
                        </span>
                    @endif
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">SKU: {{ $this->product->sku ?? '—' }}</p>
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
                                <tr><td colspan="2" class="px-4 py-3 text-gray-400">No inventory rows for this product at any warehouse.</td></tr>
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
                                    <td class="px-4 py-2">{{ $txn->created_at?->format('M j, Y g:i A') }}</td>
                                    <td class="px-4 py-2"><span class="px-2 py-0.5 text-xs rounded bg-gray-100 dark:bg-gray-700">{{ $txn->type }}</span></td>
                                    <td class="px-4 py-2">{{ $txn->warehouse?->name ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right">{{ $txn->quantity }}</td>
                                    <td class="px-4 py-2 text-gray-500">{{ $txn->reference ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $txn->user?->name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-3 text-gray-400">No transactions recorded for this product.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Count session participation --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                <h3 class="px-4 py-3 font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700">Count / Stocktake History (last 100)</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-left text-gray-500 dark:text-gray-400">
                            <tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">Session Type</th><th class="px-4 py-2">Warehouse</th><th class="px-4 py-2 text-right">Expected at Open</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($this->countSessionItems as $item)
                                <tr>
                                    <td class="px-4 py-2">{{ $item->created_at?->format('M j, Y g:i A') }}</td>
                                    <td class="px-4 py-2">{{ $item->session?->type ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $item->session?->warehouse?->name ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right">{{ $item->expected_quantity_at_open }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-3 text-gray-400">This product has never appeared in a count/stocktake session.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Stock adjustments --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                <h3 class="px-4 py-3 font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700">Stock Adjustments</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-left text-gray-500 dark:text-gray-400">
                            <tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">Reason</th><th class="px-4 py-2 text-right">Change</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Requested By</th><th class="px-4 py-2">Reviewed By</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($this->stockAdjustments as $adj)
                                <tr>
                                    <td class="px-4 py-2">{{ $adj->created_at?->format('M j, Y g:i A') }}</td>
                                    <td class="px-4 py-2">{{ str($adj->reason)->replace('_', ' ')->title() }}</td>
                                    <td class="px-4 py-2 text-right {{ $adj->quantity_change >= 0 ? 'text-success-600' : 'text-danger-600' }}">{{ $adj->quantity_change }}</td>
                                    <td class="px-4 py-2">{{ $adj->status }}</td>
                                    <td class="px-4 py-2">{{ $adj->requestedBy?->name ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $adj->reviewedBy?->name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-3 text-gray-400">No stock adjustments recorded for this product.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Deletion request history --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                <h3 class="px-4 py-3 font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700">Deletion Requests</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/50 text-left text-gray-500 dark:text-gray-400">
                            <tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">Reason</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Requested By</th><th class="px-4 py-2">Reviewed By</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($this->deletionRequests as $req)
                                <tr>
                                    <td class="px-4 py-2">{{ $req->created_at?->format('M j, Y g:i A') }}</td>
                                    <td class="px-4 py-2">{{ $req->reason }}</td>
                                    <td class="px-4 py-2">{{ $req->status }}</td>
                                    <td class="px-4 py-2">{{ $req->requestedBy?->name ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $req->reviewedBy?->name ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-3 text-gray-400">No deletion requests have ever been made for this product.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
