<x-filament-panels::page>
    @php
        $summary = $this->summary();
        $rows = $this->rows();
        $perStaff = $this->perStaff();
        $selectedNames = $this->selectedStaffNames();
    @endphp

    {{-- Filters --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">From (business day)</label>
                <input type="date" wire:model.live="dateFrom"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">To (business day)</label>
                <input type="date" wire:model.live="dateTo"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Status</label>
                <select wire:model.live="status"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
                    <option value="all">All debts</option>
                    <option value="outstanding">Pending / outstanding only</option>
                    <option value="open">Open (nothing repaid)</option>
                    <option value="partially_settled">Partially settled</option>
                    <option value="settled">Settled</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Where it came from</label>
                <select wire:model.live="origin"
                    class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm">
                    <option value="all">Handover and recorded</option>
                    <option value="handover">Raised during a handover</option>
                    <option value="recorded">Recorded by a person</option>
                </select>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @foreach ([
                'today' => 'Today',
                'week' => 'Last 7 days',
                'month' => 'This month',
                'last_month' => 'Last month',
                'quarter' => 'Last 90 days',
            ] as $preset => $label)
                <button type="button" wire:click="setRange('{{ $preset }}')"
                    class="px-3 py-1.5 text-xs rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-600">
                    {{ $label }}
                </button>
            @endforeach

            <span class="text-xs text-gray-500 dark:text-gray-400 ml-auto">
                A trading day closes at 9am WAT — a 2am shortfall counts against the night before.
            </span>
        </div>

        {{-- Staff picker: several at once, because the question is almost
             never about one person in isolation. --}}
        <div class="border-t border-gray-200 dark:border-gray-700 pt-3">
            <div class="flex flex-wrap items-center gap-2 mb-2">
                <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Staff</label>
                <input type="search" wire:model.live.debounce.300ms="staffSearch" placeholder="Search names…"
                    class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-xs py-1">
                <button type="button" wire:click="selectAllStaff"
                    class="px-2 py-1 text-xs rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">
                    Select all shown
                </button>
                <button type="button" wire:click="clearStaff"
                    class="px-2 py-1 text-xs rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200">
                    Clear
                </button>
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    @if ($selectedNames === [])
                        Everyone with a debt
                    @else
                        {{ count($selectedNames) }} selected: {{ implode(', ', array_slice($selectedNames, 0, 4)) }}{{ count($selectedNames) > 4 ? ' +' . (count($selectedNames) - 4) . ' more' : '' }}
                    @endif
                </span>
            </div>

            <div class="max-h-40 overflow-y-auto grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-x-4 gap-y-1 rounded-lg border border-gray-200 dark:border-gray-700 p-2">
                @forelse ($this->staffOptions() as $staff)
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" wire:model.live="userIds" value="{{ $staff->id }}"
                            class="rounded border-gray-300 dark:border-gray-600 text-primary-600">
                        <span class="truncate">{{ $staff->name }}</span>
                    </label>
                @empty
                    <div class="col-span-full text-sm text-gray-500 dark:text-gray-400 py-2">
                        No staff member has a debt on record{{ trim($staffSearch) !== '' ? ' matching that search' : '' }}.
                    </div>
                @endforelse
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 border-t border-gray-200 dark:border-gray-700 pt-3">
            <button type="button" wire:click="exportCsv"
                class="px-3 py-1.5 text-sm rounded-lg bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900">
                Download CSV (every debt)
            </button>
            <button type="button" wire:click="exportStaffSummaryCsv"
                class="px-3 py-1.5 text-sm rounded-lg bg-gray-700 dark:bg-gray-300 text-white dark:text-gray-900">
                Download CSV (per staff)
            </button>
            <button type="button" wire:click="exportPdf"
                class="px-3 py-1.5 text-sm rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200">
                Download PDF
            </button>
            <span class="text-xs text-gray-500 dark:text-gray-400">
                Downloads exactly what the filters above are showing — {{ number_format($summary['debts']) }} debt(s).
            </span>
        </div>
    </div>

    {{-- Summary --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        @foreach ([
            ['Charged in range', '₦' . number_format($summary['charged'], 2), 'text-gray-900 dark:text-white', $summary['debts'] . ' debt(s), ' . $summary['staff'] . ' staff'],
            ['Repaid', '₦' . number_format($summary['repaid'], 2), 'text-success-600 dark:text-success-400', $summary['settled_count'] . ' fully settled'],
            ['Still outstanding', '₦' . number_format($summary['outstanding'], 2), $summary['outstanding'] > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-gray-900 dark:text-white', $summary['pending_count'] . ' still pending'],
            ['Oldest pending', $summary['oldest_pending_days'] . ' days', $summary['oldest_pending_days'] > 30 ? 'text-danger-600 dark:text-danger-400' : 'text-gray-900 dark:text-white', 'since it was raised'],
        ] as [$label, $value, $tone, $foot])
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="text-xl font-bold {{ $tone }}">{{ $value }}</div>
                <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $foot }}</div>
            </div>
        @endforeach
    </div>

    {{-- Where the money came from: a shortfall the system worked out at
         handover and a figure somebody typed in carry very different
         weight in a dispute, so they are never blended into one number. --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        @foreach ([
            ['Raised during a handover', $summary['handover_charged'], $summary['handover_outstanding'], 'Settlement, cashier session and stock-count shortfalls the system computed.'],
            ['Recorded by a person', $summary['recorded_charged'], $summary['recorded_outstanding'], 'Manual entries and unpaid orders charged to a staff member.'],
        ] as [$label, $charged, $outstanding, $note])
            <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ $label }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mb-2">{{ $note }}</div>
                <div class="flex gap-6">
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Charged</div>
                        <div class="text-lg font-bold text-gray-900 dark:text-white">₦{{ number_format($charged, 2) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Outstanding</div>
                        <div class="text-lg font-bold {{ $outstanding > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-gray-900 dark:text-white' }}">
                            ₦{{ number_format($outstanding, 2) }}
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Per staff --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Per staff member</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50 text-left text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-2">Staff</th>
                        <th class="px-4 py-2 text-right">Debts</th>
                        <th class="px-4 py-2 text-right">Charged</th>
                        <th class="px-4 py-2 text-right">Repaid</th>
                        <th class="px-4 py-2 text-right">Outstanding</th>
                        <th class="px-4 py-2 text-right">Pending</th>
                        <th class="px-4 py-2 text-right">From handover</th>
                        <th class="px-4 py-2 text-right">Recorded</th>
                        <th class="px-4 py-2 text-right">Oldest pending</th>
                        <th class="px-4 py-2">Last debt</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($perStaff as $staff)
                        <tr class="text-gray-700 dark:text-gray-200">
                            <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $staff['staff_name'] }}</td>
                            <td class="px-4 py-2 text-right">{{ $staff['debts'] }}</td>
                            <td class="px-4 py-2 text-right">₦{{ number_format($staff['charged'], 2) }}</td>
                            <td class="px-4 py-2 text-right">₦{{ number_format($staff['repaid'], 2) }}</td>
                            <td class="px-4 py-2 text-right font-semibold {{ $staff['outstanding'] > 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">
                                ₦{{ number_format($staff['outstanding'], 2) }}
                            </td>
                            <td class="px-4 py-2 text-right">{{ $staff['pending_count'] }}</td>
                            <td class="px-4 py-2 text-right">₦{{ number_format($staff['handover_outstanding'], 2) }}</td>
                            <td class="px-4 py-2 text-right">₦{{ number_format($staff['recorded_outstanding'], 2) }}</td>
                            <td class="px-4 py-2 text-right">{{ $staff['pending_count'] > 0 ? $staff['oldest_pending_days'] . 'd' : '—' }}</td>
                            <td class="px-4 py-2">{{ $staff['last_debt_on'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                                No debts match these filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Every debt --}}
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Every debt in range</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900/50 text-left text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-2">Business day</th>
                        <th class="px-4 py-2">Recorded at</th>
                        <th class="px-4 py-2">Staff</th>
                        <th class="px-4 py-2">Source</th>
                        <th class="px-4 py-2 text-right">Amount</th>
                        <th class="px-4 py-2 text-right">Repaid</th>
                        <th class="px-4 py-2 text-right">Outstanding</th>
                        <th class="px-4 py-2">Status</th>
                        <th class="px-4 py-2">Recorded by</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($rows as $row)
                        <tr class="text-gray-700 dark:text-gray-200 align-top">
                            <td class="px-4 py-2 whitespace-nowrap">{{ $row['business_day'] }}</td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ $row['created_at']?->format('M j, Y g:ia') }}</td>
                            <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $row['staff_name'] }}</td>
                            <td class="px-4 py-2">
                                <span @class([
                                    'inline-block px-2 py-0.5 rounded text-xs font-medium',
                                    'bg-info-100 text-info-800 dark:bg-info-900/40 dark:text-info-200' => $row['origin'] === 'handover',
                                    'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' => $row['origin'] !== 'handover',
                                ])>
                                    {{ $row['origin'] === 'handover' ? 'During handover' : 'Recorded by a person' }}
                                </span>
                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    {{ $row['reason_label'] }}
                                    @if ($row['shift_id'])
                                        · shift #{{ $row['shift_id'] }}{{ $row['shift_type'] ? ' (' . $row['shift_type'] . ')' : '' }}
                                    @endif
                                    @if ($row['order_number'])
                                        · order {{ $row['order_number'] }}
                                    @endif
                                </div>
                                @if ($row['notes'])
                                    <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5 max-w-md">{{ $row['notes'] }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">₦{{ number_format($row['amount'], 2) }}</td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">₦{{ number_format($row['repaid'], 2) }}</td>
                            <td class="px-4 py-2 text-right whitespace-nowrap font-semibold {{ $row['outstanding'] > 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">
                                ₦{{ number_format($row['outstanding'], 2) }}
                            </td>
                            <td class="px-4 py-2">
                                <span @class([
                                    'inline-block px-2 py-0.5 rounded text-xs font-medium',
                                    'bg-danger-100 text-danger-800 dark:bg-danger-900/40 dark:text-danger-200' => $row['status'] === 'open',
                                    'bg-warning-100 text-warning-800 dark:bg-warning-900/40 dark:text-warning-200' => $row['status'] === 'partially_settled',
                                    'bg-success-100 text-success-800 dark:bg-success-900/40 dark:text-success-200' => $row['status'] === 'settled',
                                ])>
                                    {{ $row['status_label'] }}
                                </span>
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap">{{ $row['recorded_by'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">
                                No debts match these filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
