@php
    $byUnits = $data['fast_movers_by_units'];
    $byMargin = $data['fast_movers_by_margin'];
@endphp

<div x-data="{ rankBy: 'units' }" class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
    <div class="flex items-center justify-between mb-2">
        <div class="text-xs font-semibold text-gray-500 uppercase">Fast Movers — Top 10</div>
        <div class="flex gap-1 text-xs">
            <button type="button" @click="rankBy = 'units'" :class="rankBy === 'units' ? 'bg-primary-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-500'" class="px-2 py-1 rounded">By Units</button>
            <button type="button" @click="rankBy = 'margin'" :class="rankBy === 'margin' ? 'bg-primary-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-500'" class="px-2 py-1 rounded">By Margin</button>
        </div>
    </div>
    <div x-show="rankBy === 'units'">
        @include('filament-ceo.pages.report-explorer-tabs._chart', ['type' => 'bar', 'chartData' => ['labels' => $byUnits->pluck('item_name')->all(), 'datasets' => [['label' => 'Units sold', 'data' => $byUnits->pluck('quantity')->all(), 'backgroundColor' => '#3b82f6']]], 'options' => ['plugins' => ['legend' => ['display' => false]]]])
    </div>
    <div x-show="rankBy === 'margin'" x-cloak>
        @include('filament-ceo.pages.report-explorer-tabs._chart', ['type' => 'bar', 'chartData' => ['labels' => $byMargin->pluck('item_name')->all(), 'datasets' => [['label' => 'Margin contribution', 'data' => $byMargin->pluck('margin')->all(), 'backgroundColor' => '#10b981']]], 'options' => ['plugins' => ['legend' => ['display' => false]]]])
    </div>
</div>

<div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 mt-4 overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                <th class="p-2">Item</th><th class="p-2">Category</th><th class="p-2 text-right">Units Sold</th><th class="p-2 text-right">Margin Contribution</th><th class="p-2 text-right">Revenue</th>
            </tr>
        </thead>
        <tbody>
            @forelse($byUnits as $row)
                <tr class="border-b border-gray-100 dark:border-gray-700/50">
                    <td class="p-2">{{ $row['item_name'] }}</td>
                    <td class="p-2">{{ $row['category_name'] }}</td>
                    <td class="p-2 text-right">{{ number_format($row['quantity']) }}</td>
                    <td class="p-2 text-right">₦{{ number_format($row['margin'], 2) }}</td>
                    <td class="p-2 text-right">₦{{ number_format($row['revenue'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-4 text-center text-gray-400">No sales in this range.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <div class="text-xs font-semibold text-gray-500 uppercase p-3">Slow Movers / Dead Stock</div>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                    <th class="p-2">Product</th><th class="p-2 text-right">Stock on Hand</th><th class="p-2 text-right">Sold (range)</th><th class="p-2 text-right">Value at Cost</th>
                </tr>
            </thead>
            <tbody>
                @forelse($data['slow_movers'] as $row)
                    <tr class="border-b border-gray-100 dark:border-gray-700/50">
                        <td class="p-2">{{ $row['product']->name }}</td>
                        <td class="p-2 text-right">{{ number_format($row['stock_on_hand']) }}</td>
                        <td class="p-2 text-right">{{ number_format($row['sold_in_range']) }}</td>
                        <td class="p-2 text-right">₦{{ number_format($row['stock_value_at_cost'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="p-4 text-center text-gray-400">None.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <div class="text-xs font-semibold text-gray-500 uppercase p-3">Days of Stock (under threshold)</div>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                    <th class="p-2">Product</th><th class="p-2 text-right">Stock</th><th class="p-2 text-right">Daily Velocity</th><th class="p-2 text-right">Days Left</th>
                </tr>
            </thead>
            <tbody>
                @forelse($data['days_of_stock'] as $row)
                    <tr class="border-b border-gray-100 dark:border-gray-700/50 {{ $row['days_of_stock'] < 2 ? 'bg-red-50 dark:bg-red-900/20' : '' }}">
                        <td class="p-2">{{ $row['product']->name }}</td>
                        <td class="p-2 text-right">{{ number_format($row['stock_on_hand']) }}</td>
                        <td class="p-2 text-right">{{ number_format($row['daily_velocity'], 2) }}</td>
                        <td class="p-2 text-right font-semibold">{{ number_format($row['days_of_stock'], 1) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="p-4 text-center text-gray-400">None under threshold.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-x-auto lg:col-span-2">
        <div class="text-xs font-semibold text-gray-500 uppercase p-3">Shrinkage-Prone (shortages + damages, range)</div>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                    <th class="p-2">Product</th><th class="p-2 text-right">Damage Incidents</th><th class="p-2 text-right">Damage Qty</th><th class="p-2 text-right">Shortage Incidents</th><th class="p-2 text-right">Shortage Qty</th>
                </tr>
            </thead>
            <tbody>
                @forelse($data['shrinkage_prone'] as $row)
                    <tr class="border-b border-gray-100 dark:border-gray-700/50">
                        <td class="p-2">{{ $row['product']->name }}</td>
                        <td class="p-2 text-right">{{ $row['damage_count'] }}</td>
                        <td class="p-2 text-right">{{ number_format($row['damage_qty']) }}</td>
                        <td class="p-2 text-right">{{ $row['shortage_count'] }}</td>
                        <td class="p-2 text-right">{{ number_format($row['shortage_qty']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-4 text-center text-gray-400">None in this range.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
