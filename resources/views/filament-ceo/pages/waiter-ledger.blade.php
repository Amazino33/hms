<x-filament-panels::page>
    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 flex flex-wrap items-end gap-4">
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Mode</label>
            <select wire:model.live="mode" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                <option value="shifts">Single Waiter — Shifts</option>
                <option value="all_waiters">All Waiters</option>
            </select>
        </div>

        @if($mode === 'shifts')
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">Waiter</label>
                <select wire:model.live="waiterId" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                    <option value="">Select a waiter…</option>
                    @foreach($this->waiters() as $waiter)
                        <option value="{{ $waiter->id }}">{{ $waiter->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">From</label>
            <input type="date" wire:model.live="dateFrom" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">To</label>
            <input type="date" wire:model.live="dateTo" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
        </div>

        <div class="ml-auto flex gap-2">
            <button type="button" wire:click="exportCsv" class="px-3 py-2 text-xs font-semibold rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">Export CSV</button>
            <button type="button" wire:click="exportPdf" class="px-3 py-2 text-xs font-semibold rounded-lg bg-primary-600 text-white">Export PDF</button>
        </div>
    </div>

    @if($mode === 'shifts')
        @if(!$waiterId)
            <div class="text-sm text-gray-500 mt-4">Select a waiter to view their ledger.</div>
        @else
            @php($summary = $this->summary())
            <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mt-4">
                <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
                    <div class="text-[10px] text-gray-500 uppercase">Sales Handled</div>
                    <div class="font-bold">₦{{ number_format($summary['total_sales_handled'], 2) }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
                    <div class="text-[10px] text-gray-500 uppercase">Shortfall</div>
                    <div class="font-bold text-red-600">₦{{ number_format($summary['total_shortfall'], 2) }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
                    <div class="text-[10px] text-gray-500 uppercase">Shortfall Rate</div>
                    <div class="font-bold">{{ number_format($summary['shortfall_rate_pct'], 2) }}%</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
                    <div class="text-[10px] text-gray-500 uppercase">Orders</div>
                    <div class="font-bold">{{ $summary['orders_count'] }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
                    <div class="text-[10px] text-gray-500 uppercase">Avg Sale/Order</div>
                    <div class="font-bold">₦{{ number_format($summary['avg_sale_per_order'], 2) }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
                    <div class="text-[10px] text-gray-500 uppercase">Outstanding Debt</div>
                    <div class="font-bold">₦{{ number_format($summary['current_outstanding_debt_balance'], 2) }}</div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                            <th class="p-2">Date</th><th class="p-2 text-right">Orders</th><th class="p-2 text-right">Sales</th>
                            <th class="p-2 text-right">Cash</th><th class="p-2 text-right">POS</th><th class="p-2 text-right">Transfer</th>
                            <th class="p-2 text-right">Shortfall</th><th class="p-2 text-right">Rate</th><th class="p-2 text-right">Running Debt</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->shiftRows() as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-700/50">
                                <td class="p-2">{{ $row['date']->format('M j, Y g:ia') }}</td>
                                <td class="p-2 text-right">{{ $row['orders_count'] }}</td>
                                <td class="p-2 text-right">₦{{ number_format($row['total_sales'], 2) }}</td>
                                <td class="p-2 text-right">₦{{ number_format($row['cash_declared'], 2) }}</td>
                                <td class="p-2 text-right">₦{{ number_format($row['pos_total'], 2) }}</td>
                                <td class="p-2 text-right">₦{{ number_format($row['transfer_total'], 2) }}</td>
                                <td class="p-2 text-right {{ $row['shortfall'] > 0 ? 'text-red-600' : '' }}">₦{{ number_format($row['shortfall'], 2) }}</td>
                                <td class="p-2 text-right">{{ number_format($row['shortfall_rate_pct'], 2) }}%</td>
                                <td class="p-2 text-right">₦{{ number_format($row['running_debt_balance'], 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="p-4 text-center text-gray-400">No confirmed shifts in this range.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    @else
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                        <th class="p-2">Waiter</th><th class="p-2 text-right">Sales Handled</th>
                        <th class="p-2 text-right">Shortfall</th><th class="p-2 text-right">Shortfall Rate</th><th class="p-2 text-right">Outstanding Debt</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->allWaiterRows() as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50">
                            <td class="p-2">{{ $row['waiter_name'] }}</td>
                            <td class="p-2 text-right">₦{{ number_format($row['sales_handled'], 2) }}</td>
                            <td class="p-2 text-right {{ $row['shortfall'] > 0 ? 'text-red-600' : '' }}">₦{{ number_format($row['shortfall'], 2) }}</td>
                            <td class="p-2 text-right">{{ number_format($row['shortfall_rate_pct'], 2) }}%</td>
                            <td class="p-2 text-right">₦{{ number_format($row['outstanding_debt'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
