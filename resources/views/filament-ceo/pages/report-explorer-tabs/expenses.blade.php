@php
    $categories = $data['by_category'];
    $top5 = $categories->take(5);
    $otherTotal = $categories->skip(5)->sum('total');
    $doughnutLabels = $top5->pluck('name')->all();
    $doughnutValues = $top5->pluck('total')->all();
    if ($otherTotal > 0) { $doughnutLabels[] = 'Other'; $doughnutValues[] = $otherTotal; }
@endphp

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
        <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Category Share</div>
        @include('filament-ceo.pages.report-explorer-tabs._chart', [
            'type' => 'doughnut',
            'chartData' => ['labels' => $doughnutLabels, 'datasets' => [['data' => $doughnutValues, 'backgroundColor' => ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#6b7280']]]],
        ])
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
        <div class="text-xs font-semibold text-gray-500 uppercase mb-2">By Day</div>
        @include('filament-ceo.pages.report-explorer-tabs._chart', [
            'type' => 'bar',
            'chartData' => ['labels' => $data['by_day']->map(fn ($d) => \Carbon\CarbonImmutable::parse($d['date'])->format('M j'))->all(), 'datasets' => [['label' => 'Expenses', 'data' => $data['by_day']->pluck('total')->all(), 'backgroundColor' => '#3b82f6']]],
            'options' => ['plugins' => ['legend' => ['display' => false]]],
        ])
    </div>
</div>

<div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 mt-4">
    <div class="text-xs font-semibold text-gray-500 uppercase">Total (non-voided)</div>
    <div class="text-2xl font-bold text-gray-900 dark:text-white mt-1">₦{{ number_format($data['total'], 2) }}</div>
</div>

<div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 mt-4 overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                <th class="p-2">Date</th><th class="p-2">Category</th><th class="p-2 text-right">Amount</th><th class="p-2">Note</th><th class="p-2">Entered By</th><th class="p-2">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['rows'] as $expense)
                <tr class="border-b border-gray-100 dark:border-gray-700/50 {{ $expense->isVoided() ? 'opacity-50' : '' }}">
                    <td class="p-2">{{ $expense->date_incurred->format('M j, Y') }}</td>
                    <td class="p-2">{{ $expense->category?->name }}</td>
                    <td class="p-2 text-right">₦{{ number_format($expense->amount, 2) }}</td>
                    <td class="p-2">{{ $expense->note }}</td>
                    <td class="p-2">{{ $expense->enteredBy?->name }}</td>
                    <td class="p-2">
                        @if($expense->isVoided())
                            <span class="text-red-500 text-xs">Voided</span>
                        @else
                            <span class="text-emerald-600 text-xs">Active</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-4 text-center text-gray-400">No expenses in this range.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
