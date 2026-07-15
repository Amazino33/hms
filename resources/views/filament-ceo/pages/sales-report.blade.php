<x-filament-panels::page>
    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">From</label>
            <input type="date" wire:model.live="dateFrom" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">To</label>
            <input type="date" wire:model.live="dateTo" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm" />
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Category</label>
            <select wire:model.live="categoryId" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                <option value="">All</option>
                @foreach($this->categories() as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Product</label>
            <select wire:model.live="productId" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                <option value="">All</option>
                @foreach($this->products() as $p)
                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Sold By</label>
            <select wire:model.live="soldBy" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                <option value="">All</option>
                @foreach($this->waiters() as $w)
                    <option value="{{ $w->id }}">{{ $w->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Source</label>
            <select wire:model.live="source" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                <option value="">All</option>
                <option value="bar">Bar</option>
                <option value="restaurant">Restaurant</option>
                <option value="rooms">Rooms</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Billed Via</label>
            <select wire:model.live="billedVia" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                <option value="">Any</option>
                <option value="direct">Direct</option>
                <option value="folio">Folio</option>
            </select>
        </div>

        <div class="ml-auto flex gap-2">
            <button type="button" wire:click="exportCsv" class="px-3 py-2 text-xs font-semibold rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">Export CSV</button>
            <button type="button" wire:click="exportPdf" class="px-3 py-2 text-xs font-semibold rounded-lg bg-primary-600 text-white">Export PDF</button>
        </div>
    </div>

    @php($summary = $this->summary())
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mt-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
            <div class="text-[10px] text-gray-500 uppercase">Total Quantity</div>
            <div class="font-bold">{{ number_format($summary['quantity']) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
            <div class="text-[10px] text-gray-500 uppercase">Total Revenue</div>
            <div class="font-bold">₦{{ number_format($summary['revenue'], 2) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
            <div class="text-[10px] text-gray-500 uppercase">Total COGS</div>
            <div class="font-bold">₦{{ number_format($summary['cost'], 2) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
            <div class="text-[10px] text-gray-500 uppercase">Total Margin</div>
            <div class="font-bold">₦{{ number_format($summary['margin'], 2) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
            <div class="text-[10px] text-gray-500 uppercase">Margin %</div>
            <div class="font-bold">{{ number_format($summary['margin_pct'], 2) }}%</div>
        </div>
    </div>
    <div class="text-[10px] text-gray-400 mt-1">Margin computed at current cost — not historical COGS at time of sale.</div>

    @if($this->isRoomsOnly())
        <div class="text-sm text-gray-500 mt-4">Room-night revenue has no per-product breakdown — see the Occupancy Report for nightly detail.</div>
    @else
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                        <th class="p-2">Item</th><th class="p-2">Category</th><th class="p-2 text-right">Qty</th>
                        <th class="p-2 text-right">Revenue</th><th class="p-2 text-right">Cost</th><th class="p-2 text-right">Margin</th>
                        <th class="p-2 text-right">Margin %</th><th class="p-2 text-right">Contribution %</th>
                    </tr>
                </thead>
                <tbody>
                    @php($grouped = $this->productRows()->groupBy('category_name'))
                    @forelse($grouped as $categoryName => $rows)
                        @foreach($rows as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-700/50">
                                <td class="p-2">{{ $row['item_name'] }}</td>
                                <td class="p-2">{{ $row['category_name'] }}</td>
                                <td class="p-2 text-right">{{ number_format($row['quantity']) }}</td>
                                <td class="p-2 text-right">₦{{ number_format($row['revenue'], 2) }}</td>
                                <td class="p-2 text-right">₦{{ number_format($row['cost'], 2) }}</td>
                                <td class="p-2 text-right">₦{{ number_format($row['margin'], 2) }}</td>
                                <td class="p-2 text-right">{{ number_format($row['margin_pct'], 2) }}%</td>
                                <td class="p-2 text-right">{{ number_format($row['revenue_contribution_pct'], 2) }}%</td>
                            </tr>
                        @endforeach
                        <tr class="bg-gray-50 dark:bg-gray-700/40 font-semibold">
                            <td class="p-2" colspan="2">Subtotal — {{ $categoryName }}</td>
                            <td class="p-2 text-right">{{ number_format($rows->sum('quantity')) }}</td>
                            <td class="p-2 text-right">₦{{ number_format($rows->sum('revenue'), 2) }}</td>
                            <td class="p-2 text-right">₦{{ number_format($rows->sum('cost'), 2) }}</td>
                            <td class="p-2 text-right">₦{{ number_format($rows->sum('margin'), 2) }}</td>
                            <td class="p-2 text-right">—</td>
                            <td class="p-2 text-right">{{ number_format($rows->sum('revenue_contribution_pct'), 2) }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-4 text-center text-gray-400">No sales in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
