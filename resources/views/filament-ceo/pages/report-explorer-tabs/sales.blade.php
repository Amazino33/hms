@php
    $paymentTotals = collect(['cash', 'pos', 'transfer', 'split'])->mapWithKeys(fn ($m) => [$m => $data['payment_mix']->sum(fn ($d) => $d['by_method'][$m] ?? 0)]);
@endphp

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 lg:col-span-2">
        <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Revenue Over Time</div>
        @include('filament-ceo.pages.report-explorer-tabs._chart', [
            'type' => 'line',
            'chartData' => ['labels' => $data['daily']->map(fn ($d) => $d['date']->format('M j'))->all(), 'datasets' => [['label' => 'Revenue', 'data' => $data['daily']->pluck('revenue')->all(), 'borderColor' => '#3b82f6', 'backgroundColor' => 'rgba(59,130,246,0.1)', 'fill' => true, 'tension' => 0.3]]],
        ])
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
        <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Payment Mix</div>
        @include('filament-ceo.pages.report-explorer-tabs._chart', [
            'type' => 'doughnut',
            'chartData' => ['labels' => $paymentTotals->keys()->map(fn ($k) => ucfirst($k))->all(), 'datasets' => [['data' => $paymentTotals->values()->all(), 'backgroundColor' => ['#10b981', '#3b82f6', '#f59e0b', '#8b5cf6']]]],
        ])
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 lg:col-span-3">
        <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Revenue Center Comparison</div>
        @include('filament-ceo.pages.report-explorer-tabs._chart', [
            'type' => 'bar',
            'chartData' => ['labels' => ['Bar', 'Restaurant', 'Rooms'], 'datasets' => [['label' => 'Revenue', 'data' => [$data['mix']['bar'], $data['mix']['restaurant'], $data['mix']['rooms']], 'backgroundColor' => ['#3b82f6', '#10b981', '#f59e0b']]]],
            'options' => ['plugins' => ['legend' => ['display' => false]]],
            'height' => 180,
        ])
    </div>
</div>

<div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 mt-4 overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                <th class="p-2">Item</th><th class="p-2">Category</th><th class="p-2">Source</th><th class="p-2">Folio</th>
                <th class="p-2 text-right">Qty</th><th class="p-2 text-right">Revenue</th><th class="p-2 text-right">Cost</th><th class="p-2 text-right">Margin</th><th class="p-2">Date</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['rows'] as $row)
                <tr class="border-b border-gray-100 dark:border-gray-700/50">
                    <td class="p-2">{{ $row['item_name'] }}</td>
                    <td class="p-2">{{ $row['category_name'] }}</td>
                    <td class="p-2">{{ ucfirst($row['source']) }}</td>
                    <td class="p-2">{{ $row['billed_via_folio'] ? 'Folio' : 'Direct' }}</td>
                    <td class="p-2 text-right">{{ number_format($row['quantity']) }}</td>
                    <td class="p-2 text-right">₦{{ number_format($row['revenue'], 2) }}</td>
                    <td class="p-2 text-right">₦{{ number_format($row['cost'], 2) }}{{ $row['cost_estimated'] ? ' *' : '' }}</td>
                    <td class="p-2 text-right">₦{{ number_format($row['margin'], 2) }}</td>
                    <td class="p-2">{{ $row['date']?->format('M j, H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="p-4 text-center text-gray-400">No sales in this range.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="text-[10px] text-gray-400 p-2">* cost estimated at current price — no cost-at-sale snapshot for this line.</div>
</div>
