<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
        <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Occupancy Over Time</div>
        @include('filament-ceo.pages.report-explorer-tabs._chart', [
            'type' => 'line',
            'chartData' => ['labels' => $data['nightly']->map(fn ($d) => $d['date']->format('M j'))->all(), 'datasets' => [['label' => 'Occupancy %', 'data' => $data['nightly']->pluck('occupancy_pct')->all(), 'borderColor' => '#3b82f6', 'backgroundColor' => 'rgba(59,130,246,0.1)', 'fill' => true, 'tension' => 0.3]]],
        ])
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
        <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Revenue by Day</div>
        @include('filament-ceo.pages.report-explorer-tabs._chart', [
            'type' => 'line',
            'chartData' => ['labels' => $data['nightly']->map(fn ($d) => $d['date']->format('M j'))->all(), 'datasets' => [['label' => 'Room revenue', 'data' => $data['nightly']->pluck('room_revenue')->all(), 'borderColor' => '#10b981', 'backgroundColor' => 'rgba(16,185,129,0.1)', 'fill' => true, 'tension' => 0.3]]],
        ])
    </div>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
        <div class="text-[10px] text-gray-500 uppercase">Avg Occupancy</div>
        <div class="font-bold">{{ number_format($data['summary']['average_occupancy_pct'], 1) }}%</div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
        <div class="text-[10px] text-gray-500 uppercase">Room Revenue</div>
        <div class="font-bold">₦{{ number_format($data['summary']['total_room_revenue'], 2) }}</div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
        <div class="text-[10px] text-gray-500 uppercase">ADR</div>
        <div class="font-bold">₦{{ number_format($data['summary']['adr'], 2) }}</div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
        <div class="text-[10px] text-gray-500 uppercase">RevPAR</div>
        <div class="font-bold">₦{{ number_format($data['summary']['revpar'], 2) }}</div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <div class="text-xs font-semibold text-gray-500 uppercase p-3">Room-Nights by Room</div>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                    <th class="p-2">Room</th><th class="p-2 text-right">Nights Sold</th><th class="p-2 text-right">Revenue</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data['by_room'] as $row)
                    <tr class="border-b border-gray-100 dark:border-gray-700/50">
                        <td class="p-2">{{ $row['room']->number }}</td>
                        <td class="p-2 text-right">{{ $row['nights_sold'] }}</td>
                        <td class="p-2 text-right">₦{{ number_format($row['revenue'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-x-auto">
        <div class="text-xs font-semibold text-gray-500 uppercase p-3">Open Folios (in-house, as of now)</div>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                    <th class="p-2">Room</th><th class="p-2">Guest</th><th class="p-2 text-right">Balance</th>
                </tr>
            </thead>
            <tbody>
                @forelse($data['open_folios'] as $row)
                    <tr class="border-b border-gray-100 dark:border-gray-700/50">
                        <td class="p-2">{{ $row['booking']->room?->number }}</td>
                        <td class="p-2">{{ $row['booking']->guest?->name }}</td>
                        <td class="p-2 text-right">₦{{ number_format($row['balance'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="p-4 text-center text-gray-400">No open folio balances.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
