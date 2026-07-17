@php
    $top5 = $data['by_product']->take(5);
    $otherTotal = $data['by_product']->skip(5)->sum('cost');
    $doughnutLabels = $top5->pluck('name')->all();
    $doughnutValues = $top5->pluck('cost')->all();
    if ($otherTotal > 0) { $doughnutLabels[] = 'Other'; $doughnutValues[] = $otherTotal; }
@endphp

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
        <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Cost by Product</div>
        @if($doughnutValues)
            @include('filament-ceo.pages.report-explorer-tabs._chart', [
                'type' => 'doughnut',
                'chartData' => ['labels' => $doughnutLabels, 'datasets' => [['data' => $doughnutValues, 'backgroundColor' => ['#ef4444', '#f59e0b', '#8b5cf6', '#3b82f6', '#ec4899', '#6b7280']]]],
            ])
        @else
            <div class="text-sm text-gray-400 py-8 text-center">No approved write-offs in this range.</div>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
        <div class="text-xs font-semibold text-gray-500 uppercase">Total Cost (Approved)</div>
        <div class="text-2xl font-bold text-gray-900 dark:text-white mt-1">₦{{ number_format($data['total_cost'], 2) }}</div>
        @if($data['pending']->count() > 0)
            <div class="text-xs text-amber-600 font-semibold mt-2">{{ $data['pending']->count() }} report(s) awaiting approval — see Handover Discrepancies to act.</div>
        @endif
    </div>
</div>

<div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 mt-4 overflow-x-auto">
    <div class="text-xs font-semibold text-gray-500 uppercase p-3">Approved Write-Offs</div>
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                <th class="p-2">Item</th><th class="p-2 text-right">Qty</th><th class="p-2 text-right">Cost</th><th class="p-2">Warehouse</th><th class="p-2">Reported By</th><th class="p-2">Resolved By</th><th class="p-2">Resolved At</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['approved'] as $row)
                <tr class="border-b border-gray-100 dark:border-gray-700/50">
                    <td class="p-2">{{ $row['report']->itemName() }}</td>
                    <td class="p-2 text-right">{{ number_format($row['report']->quantity, 2) }}</td>
                    <td class="p-2 text-right">₦{{ number_format($row['cost'], 2) }}</td>
                    <td class="p-2">{{ $row['report']->warehouse?->name }}</td>
                    <td class="p-2">{{ $row['report']->reportedBy?->name }}</td>
                    <td class="p-2">{{ $row['report']->resolvedBy?->name }}</td>
                    <td class="p-2">{{ $row['report']->resolved_at?->format('M j, H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="p-4 text-center text-gray-400">None in this range.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 mt-4 overflow-x-auto">
    <div class="text-xs font-semibold text-gray-500 uppercase p-3">Rejected (range)</div>
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                <th class="p-2">Item</th><th class="p-2 text-right">Qty</th><th class="p-2">Resolved By</th><th class="p-2">Note</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data['rejected'] as $r)
                <tr class="border-b border-gray-100 dark:border-gray-700/50">
                    <td class="p-2">{{ $r->itemName() }}</td>
                    <td class="p-2 text-right">{{ number_format($r->quantity, 2) }}</td>
                    <td class="p-2">{{ $r->resolvedBy?->name }}</td>
                    <td class="p-2">{{ $r->resolution_note }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-4 text-center text-gray-400">None in this range.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
