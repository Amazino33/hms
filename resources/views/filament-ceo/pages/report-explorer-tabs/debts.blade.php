@php($aging = $data['aging'])
@php($totalAging = max(0.01, array_sum($aging)))

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
        <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Outstanding by Staff</div>
        @include('filament-ceo.pages.report-explorer-tabs._chart', [
            'type' => 'bar',
            'chartData' => ['labels' => $data['rows']->pluck('user_name')->all(), 'datasets' => [['label' => 'Outstanding', 'data' => $data['rows']->pluck('outstanding')->all(), 'backgroundColor' => '#f59e0b']]],
            'options' => ['plugins' => ['legend' => ['display' => false]]],
        ])
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
        <div class="text-xs font-semibold text-gray-500 uppercase mb-2">New Debt vs Repayments Over Time</div>
        @include('filament-ceo.pages.report-explorer-tabs._chart', [
            'type' => 'line',
            'chartData' => ['labels' => $data['daily']->map(fn ($d) => $d['date']->format('M j'))->all(), 'datasets' => [
                ['label' => 'New debt', 'data' => $data['daily']->pluck('new')->all(), 'borderColor' => '#ef4444', 'backgroundColor' => 'transparent', 'tension' => 0.3],
                ['label' => 'Repaid', 'data' => $data['daily']->pluck('repaid')->all(), 'borderColor' => '#10b981', 'backgroundColor' => 'transparent', 'tension' => 0.3],
            ]],
        ])
    </div>
</div>

<div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 mt-4">
    <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Current Aging (as of now)</div>
    <div class="flex h-3 rounded overflow-hidden">
        <div class="bg-emerald-400" style="width: {{ $aging['aging_0_7'] / $totalAging * 100 }}%"></div>
        <div class="bg-amber-400" style="width: {{ $aging['aging_8_30'] / $totalAging * 100 }}%"></div>
        <div class="bg-red-400" style="width: {{ $aging['aging_30_plus'] / $totalAging * 100 }}%"></div>
    </div>
    <div class="text-[11px] text-gray-400 mt-1">0–7d ₦{{ number_format($aging['aging_0_7'], 2) }} · 8–30d ₦{{ number_format($aging['aging_8_30'], 2) }} · 30+d ₦{{ number_format($aging['aging_30_plus'], 2) }}</div>
</div>

<div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 mt-4 overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                <th class="p-2">Staff</th><th class="p-2 text-right">Incurred (count)</th><th class="p-2 text-right">Incurred (₦)</th>
                <th class="p-2 text-right">Repaid</th><th class="p-2 text-right">Outstanding</th><th class="p-2 text-right">Repayment %</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['rows'] as $row)
                <tr class="border-b border-gray-100 dark:border-gray-700/50">
                    <td class="p-2">{{ $row['user_name'] }}</td>
                    <td class="p-2 text-right">{{ $row['debts_incurred_count'] }}</td>
                    <td class="p-2 text-right">₦{{ number_format($row['debts_incurred_amount'], 2) }}</td>
                    <td class="p-2 text-right">₦{{ number_format($row['repaid'], 2) }}</td>
                    <td class="p-2 text-right font-semibold">₦{{ number_format($row['outstanding'], 2) }}</td>
                    <td class="p-2 text-right">{{ number_format($row['repayment_ratio_pct'], 1) }}%</td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-4 text-center text-gray-400">No debt activity in this range.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
