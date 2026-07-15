<x-filament-panels::page>
    @php($d = $this->data())

    <div class="flex items-center justify-between">
        <div class="text-lg font-bold text-gray-900 dark:text-white">{{ $d['range']->start->format('l, F j, Y') }}</div>
        <button type="button" wire:click="exportPdf" class="px-3 py-2 text-xs font-semibold rounded-lg bg-primary-600 text-white">Export PDF</button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-500 uppercase">Revenue</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white mt-1">₦{{ number_format($d['revenue_total'], 2) }}</div>
            <div class="text-xs text-gray-500 mt-2">
                Bar ₦{{ number_format($d['revenue_mix']['bar'], 2) }} ·
                Restaurant ₦{{ number_format($d['revenue_mix']['restaurant'], 2) }} ·
                Rooms ₦{{ number_format($d['revenue_mix']['rooms'], 2) }}
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-500 uppercase">Payment Mix</div>
            <div class="text-xs text-gray-500 mt-2 space-y-1">
                @forelse($d['payment_mix'] as $method => $amount)
                    <div class="flex justify-between"><span>{{ ucfirst($method) }}</span><span>₦{{ number_format($amount, 2) }}</span></div>
                @empty
                    <div>No payments recorded.</div>
                @endforelse
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-500 uppercase">Shortfalls Incurred</div>
            <div class="text-2xl font-bold text-red-600 mt-1">₦{{ number_format($d['shortfalls_incurred'], 2) }}</div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="text-xs font-semibold text-gray-500 uppercase">Occupancy</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white mt-1">{{ number_format($d['occupancy_pct'], 2) }}%</div>
            <div class="text-xs text-gray-500 mt-2">{{ $d['arrivals'] }} arrivals · {{ $d['departures'] }} departures</div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 md:col-span-2">
            <div class="text-xs font-semibold text-gray-500 uppercase">Exceptions</div>
            <div class="text-sm text-gray-700 dark:text-gray-300 mt-2 space-y-1">
                <div>New unverified transfers: <span class="font-bold">{{ $d['new_unverified_transfers'] }}</span></div>
                <div>Voids: <span class="font-bold">{{ $d['voids_count'] }}</span> (₦{{ number_format($d['voids_value'], 2) }})</div>
                <div>Unsealed handovers past expected time: <span class="font-bold">{{ $d['unsealed_handovers'] }}</span></div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
