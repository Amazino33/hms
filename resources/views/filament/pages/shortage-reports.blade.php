<x-filament-panels::page>
    <div class="space-y-8">
        <div class="bg-white dark:bg-gray-900 rounded-lg p-6 border border-gray-200 dark:border-gray-700">
            <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Per-Product Shortage Trend</h2>
            <div class="flex gap-3 items-end mb-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">From</label>
                    <input type="date" wire:model.live="from" class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">Until</label>
                    <input type="date" wire:model.live="until" class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                </div>
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                        <th class="py-2">Item</th>
                        <th class="py-2 text-center">Occurrences</th>
                        <th class="py-2 text-center">Total Qty Short</th>
                        <th class="py-2 text-right">Total ₦</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($this->shortageTrend() as $row)
                        <tr>
                            <td class="py-2 font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</td>
                            <td class="py-2 text-center">{{ $row['occurrences'] }}</td>
                            <td class="py-2 text-center font-mono">{{ number_format($row['total_quantity'], 2) }}</td>
                            <td class="py-2 text-right font-mono font-bold text-red-600">₦{{ number_format($row['total_value'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-6 text-center text-gray-500 dark:text-gray-400">No shortages in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="bg-white dark:bg-gray-900 rounded-lg p-6 border border-gray-200 dark:border-gray-700">
            <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Monthly Bartender/Chef Summary</h2>
            <div class="mb-4">
                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">Month</label>
                <input type="month" wire:model.live="month" class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100">
            </div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                        <th class="py-2">Staff</th>
                        <th class="py-2 text-right">Shortage ₦</th>
                        <th class="py-2 text-right">Debited ₦</th>
                        <th class="py-2 text-right">Written Off ₦</th>
                        <th class="py-2 text-right">Repaid This Month ₦</th>
                        <th class="py-2 text-right">Outstanding Now ₦</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($this->monthlySummary() as $row)
                        <tr>
                            <td class="py-2 font-medium text-gray-900 dark:text-white">{{ $row['name'] }}</td>
                            <td class="py-2 text-right font-mono">₦{{ number_format($row['total_shortage'], 2) }}</td>
                            <td class="py-2 text-right font-mono text-red-600">₦{{ number_format($row['total_debited'], 2) }}</td>
                            <td class="py-2 text-right font-mono text-green-600">₦{{ number_format($row['total_written_off'], 2) }}</td>
                            <td class="py-2 text-right font-mono text-blue-600">₦{{ number_format($row['total_repaid'], 2) }}</td>
                            <td class="py-2 text-right font-mono font-bold {{ $row['outstanding_now'] > 0 ? 'text-red-600' : 'text-gray-400' }}">₦{{ number_format($row['outstanding_now'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-gray-500 dark:text-gray-400">No activity for this month.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
