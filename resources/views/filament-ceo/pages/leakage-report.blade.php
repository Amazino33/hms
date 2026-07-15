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
            <label class="block text-xs font-semibold text-gray-500 mb-1">Staff</label>
            <select wire:model.live="userId" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                <option value="">All</option>
                @foreach($this->staff() as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Source</label>
            <select wire:model.live="reason" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                <option value="">All</option>
                @foreach($this->reasons() as $r)
                    <option value="{{ $r }}">{{ $r }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Status</label>
            <select wire:model.live="status" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                <option value="all">All</option>
                <option value="outstanding">Outstanding</option>
                <option value="repaid">Repaid</option>
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
            <div class="text-[10px] text-gray-500 uppercase">Incurred (Period)</div>
            <div class="font-bold">₦{{ number_format($summary['total_incurred'], 2) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
            <div class="text-[10px] text-gray-500 uppercase">Repaid (Period)</div>
            <div class="font-bold">₦{{ number_format($summary['total_repaid'], 2) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <div class="text-[10px] text-gray-500 uppercase">Outstanding</div>
                <span class="text-[9px] px-1 rounded bg-gray-100 dark:bg-gray-700 text-gray-500">as of now</span>
            </div>
            <div class="font-bold">₦{{ number_format($summary['total_outstanding_now'], 2) }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
            <div class="text-[10px] text-gray-500 uppercase">Repayment Ratio</div>
            <div class="font-bold">{{ number_format($summary['repayment_ratio_pct'], 2) }}%</div>
            @if($summary['trend']['percent'] !== null)
                <div class="text-[10px] {{ $summary['trend']['absolute'] <= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                    Leakage trend: {{ $summary['trend']['absolute'] > 0 ? '▲' : '▼' }} {{ number_format(abs($summary['trend']['percent']), 1) }}% vs prior period
                </div>
            @endif
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 mt-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500 border-b border-gray-200 dark:border-gray-700">
                    <th class="p-2">Staff</th><th class="p-2 text-right">Incurred (#)</th><th class="p-2 text-right">Incurred (₦)</th>
                    <th class="p-2 text-right">Repaid</th><th class="p-2 text-right">Outstanding</th>
                    <th class="p-2 text-right">0-7d</th><th class="p-2 text-right">8-30d</th><th class="p-2 text-right">30+d</th>
                    <th class="p-2 text-right">Repayment Ratio</th>
                </tr>
            </thead>
            <tbody>
                @forelse($this->rows() as $row)
                    <tr class="border-b border-gray-100 dark:border-gray-700/50 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/30" wire:click="toggleExpand({{ $row['user_id'] }})">
                        <td class="p-2 font-semibold">{{ $row['user_name'] }}</td>
                        <td class="p-2 text-right">{{ $row['debts_incurred_count'] }}</td>
                        <td class="p-2 text-right">₦{{ number_format($row['debts_incurred_amount'], 2) }}</td>
                        <td class="p-2 text-right">₦{{ number_format($row['repaid'], 2) }}</td>
                        <td class="p-2 text-right">₦{{ number_format($row['outstanding'], 2) }}</td>
                        <td class="p-2 text-right">₦{{ number_format($row['aging_0_7'], 2) }}</td>
                        <td class="p-2 text-right">₦{{ number_format($row['aging_8_30'], 2) }}</td>
                        <td class="p-2 text-right">₦{{ number_format($row['aging_30_plus'], 2) }}</td>
                        <td class="p-2 text-right">{{ number_format($row['repayment_ratio_pct'], 2) }}%</td>
                    </tr>
                    @if($expandedUserId === $row['user_id'])
                        <tr>
                            <td colspan="9" class="p-3 bg-gray-50 dark:bg-gray-700/30">
                                <table class="w-full text-xs">
                                    <thead>
                                        <tr class="text-left text-gray-500">
                                            <th class="p-1">Date</th><th class="p-1">Reason</th><th class="p-1 text-right">Amount</th><th class="p-1 text-right">Repaid</th><th class="p-1 text-right">Remaining</th><th class="p-1">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($this->debtsFor($row['user_id']) as $debt)
                                            <tr>
                                                <td class="p-1">{{ $debt->created_at->format('M j, Y') }}</td>
                                                <td class="p-1">{{ $debt->reason }}</td>
                                                <td class="p-1 text-right">₦{{ number_format($debt->amount, 2) }}</td>
                                                <td class="p-1 text-right">₦{{ number_format($debt->totalRepaid(), 2) }}</td>
                                                <td class="p-1 text-right">₦{{ number_format($debt->remainingBalance(), 2) }}</td>
                                                <td class="p-1">{{ $debt->status }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="9" class="p-4 text-center text-gray-400">No debts in this range.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
