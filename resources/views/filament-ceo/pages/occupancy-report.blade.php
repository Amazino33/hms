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
            <label class="block text-xs font-semibold text-gray-500 mb-1">Room</label>
            <select wire:model.live="roomId" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                <option value="">All Rooms</option>
                @foreach($this->rooms() as $room)
                    <option value="{{ $room->id }}">{{ $room->number }}</option>
                @endforeach
            </select>
        </div>
        <div class="ml-auto flex gap-2">
            <button type="button" wire:click="exportCsv" class="px-3 py-2 text-xs font-semibold rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">Export CSV</button>
            <button type="button" wire:click="exportPdf" class="px-3 py-2 text-xs font-semibold rounded-lg bg-primary-600 text-white">Export PDF</button>
        </div>
    </div>

    @php($summary = $this->summary())
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
            <div class="text-[10px] text-gray-500 uppercase">Average Occupancy</div>
            <div class="font-bold">{{ number_format($summary['average_occupancy_pct'], 2) }}%</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
            <div class="text-[10px] text-gray-500 uppercase">Total Room Revenue</div>
            <div class="font-bold">₦{{ number_format($summary['total_room_revenue'], 2) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
            <div class="text-[10px] text-gray-500 uppercase">ADR</div>
            <div class="font-bold">₦{{ number_format($summary['adr'], 2) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
            <div class="text-[10px] text-gray-500 uppercase">RevPAR</div>
            <div class="font-bold">₦{{ number_format($summary['revpar'], 2) }}</div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 mt-4">
        <div class="text-xs font-semibold text-gray-500 uppercase mb-2">Day-of-Week Averages</div>
        <div class="grid grid-cols-7 gap-2 text-center">
            @foreach(['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day)
                <div>
                    <div class="text-[10px] text-gray-400">{{ substr($day, 0, 3) }}</div>
                    <div class="font-bold text-sm">{{ number_format($summary['day_of_week_averages'][$day] ?? 0, 1) }}%</div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 mt-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                    <th class="p-2">Date</th><th class="p-2 text-right">Rooms Occupied</th><th class="p-2 text-right">Occupancy %</th>
                    <th class="p-2 text-right">Room Revenue</th><th class="p-2 text-right">Arrivals</th><th class="p-2 text-right">Departures</th>
                </tr>
            </thead>
            <tbody>
                @foreach($this->dailyRows() as $row)
                    <tr class="border-b border-gray-100 dark:border-gray-700/50">
                        <td class="p-2">{{ $row['date']->format('D, M j Y') }}</td>
                        <td class="p-2 text-right">{{ $row['rooms_occupied'] }}</td>
                        <td class="p-2 text-right">{{ number_format($row['occupancy_pct'], 2) }}%</td>
                        <td class="p-2 text-right">₦{{ number_format($row['room_revenue'], 2) }}</td>
                        <td class="p-2 text-right">{{ $row['arrivals'] }}</td>
                        <td class="p-2 text-right">{{ $row['departures'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
