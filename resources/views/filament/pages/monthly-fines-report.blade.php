@php
    $rows = $this->rows();
    $totals = $this->totals();
@endphp

<x-filament-panels::page>
    <div class="max-w-xs">
        {{ $this->form }}
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-white/5">
            <div class="text-2xl font-semibold text-gray-950 dark:text-white">
                ₦{{ number_format($totals['shadow_fines']) }}
            </div>
            <div class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">Shadow fines (not charged)</div>
        </div>
        <div class="rounded-xl border border-red-200 bg-red-50 p-3 dark:border-red-500/30 dark:bg-red-500/10">
            <div class="text-2xl font-semibold text-red-700 dark:text-red-300">
                ₦{{ number_format($totals['live_fines']) }}
            </div>
            <div class="mt-0.5 text-xs text-red-700/80 dark:text-red-300/80">Live fines</div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-white/5">
            <div class="text-2xl font-semibold text-gray-950 dark:text-white">
                ₦{{ number_format($totals['shadow_pay']) }}
            </div>
            <div class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">Shadow pay withheld</div>
        </div>
        <div class="rounded-xl border border-red-200 bg-red-50 p-3 dark:border-red-500/30 dark:bg-red-500/10">
            <div class="text-2xl font-semibold text-red-700 dark:text-red-300">
                ₦{{ number_format($totals['live_pay']) }}
            </div>
            <div class="mt-0.5 text-xs text-red-700/80 dark:text-red-300/80">Live pay withheld</div>
        </div>
    </div>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Shadow and live are kept apart on purpose. One is a projection of what the rules
        would have cost; the other is money. A single total blurring the two is how
        somebody ends up being told they owe a figure that was never real.
    </p>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="py-2 pr-4 font-medium">Staff</th>
                    <th class="py-2 pr-4 font-medium">Late</th>
                    <th class="py-2 pr-4 font-medium">Late relief</th>
                    <th class="py-2 pr-4 font-medium">Left early</th>
                    <th class="py-2 pr-4 font-medium">No clock-out</th>
                    <th class="py-2 pr-4 font-medium">Absent</th>
                    <th class="py-2 pr-4 font-medium">Shadow</th>
                    <th class="py-2 font-medium">Live</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($rows as $row)
                    <tr>
                        <td class="py-2 pr-4 font-medium text-gray-950 dark:text-white">{{ $row['staff'] }}</td>
                        <td class="py-2 pr-4">₦{{ number_format($row['types']['late']) }}</td>
                        <td class="py-2 pr-4">₦{{ number_format($row['types']['late_relief']) }}</td>
                        <td class="py-2 pr-4">₦{{ number_format($row['types']['early_leave']) }}</td>
                        <td class="py-2 pr-4">₦{{ number_format($row['types']['no_clockout']) }}</td>
                        <td class="py-2 pr-4">₦{{ number_format($row['types']['absent']) }}</td>
                        <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">
                            ₦{{ number_format($row['shadow_fines'] + $row['shadow_pay']) }}
                        </td>
                        <td class="py-2 font-medium text-red-700 dark:text-red-300">
                            ₦{{ number_format($row['live_fines'] + $row['live_pay']) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="py-6 text-center text-gray-500 dark:text-gray-400">
                            No charges recorded for this month.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
