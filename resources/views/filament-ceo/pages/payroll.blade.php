<x-filament-panels::page>
    @php($run = $this->selectedRun)

    <div class="flex flex-wrap gap-2">
        @foreach ($this->runs() as $r)
            <button
                type="button"
                wire:click="selectRun({{ $r->id }})"
                @class([
                    'fi-btn rounded-lg px-3 py-1.5 text-sm font-medium',
                    'bg-primary-600 text-white' => $this->selectedRunId === $r->id,
                    'bg-gray-100 dark:bg-white/5' => $this->selectedRunId !== $r->id,
                ])
            >
                {{ $r->period_start->format('M j') }} – {{ $r->period_end->format('M j, Y') }}
                <span class="opacity-70">({{ ucfirst($r->status) }})</span>
            </button>
        @endforeach
    </div>

    @if ($run)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 space-y-2">
            <div class="text-lg font-semibold">
                {{ $run->period_start->format('M j') }} – {{ $run->period_end->format('M j, Y') }}
                <span class="text-sm font-normal text-gray-500 dark:text-gray-400">· {{ ucfirst($run->status) }}</span>
            </div>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                Prepared by {{ $run->preparer?->name ?? '—' }}
                @if ($run->sealed_at)
                    · Sealed {{ $run->sealed_at->format('M j, Y g:i A') }}
                @endif
            </div>
        </div>

        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5 text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">Staff</th>
                        <th class="px-4 py-3 text-right">Gross</th>
                        <th class="px-4 py-3 text-right">Deduction</th>
                        <th class="px-4 py-3 text-right">Net</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Payment</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($run->lines->sortBy(fn ($l) => $l->user?->name) as $line)
                        <tr class="align-top">
                            <td class="px-4 py-3 font-medium">{{ $line->user?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">₦{{ number_format($line->gross_amount, 2) }}</td>
                            <td class="px-4 py-3 text-right">₦{{ number_format($line->deduction_amount, 2) }}</td>
                            <td class="px-4 py-3 text-right font-semibold">₦{{ number_format($line->net_amount, 2) }}</td>
                            <td class="px-4 py-3">
                                <span class="text-xs">{{ ucfirst(str_replace('_', ' ', $line->status)) }}</span>
                                @if ($line->status === 'disputed')
                                    <div class="mt-1 text-xs text-danger-600">
                                        {{ $line->dispute_reason }}
                                        @if ($line->dispute_reported_amount)
                                            (reported received: ₦{{ number_format($line->dispute_reported_amount, 2) }})
                                        @endif
                                    </div>
                                @endif
                                @if ($line->status === 'paid' || $line->status === 'acknowledged')
                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ ucfirst($line->payment_method) }}
                                        @if ($line->payment_reference)
                                            · {{ $line->payment_reference }}
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($line->status === 'pending')
                                    <div class="flex flex-col gap-1">
                                        <select wire:model="paymentMethod.{{ $line->id }}" class="fi-select-input rounded-lg border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-800">
                                            <option value="cash">Cash</option>
                                            <option value="transfer">Transfer</option>
                                        </select>
                                        <input type="text" wire:model="paymentReference.{{ $line->id }}" placeholder="Reference (optional)" class="fi-input rounded-lg border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-800">
                                        <button type="button" wire:click="markPaid({{ $line->id }})" wire:confirm="Mark {{ $line->user?->name }}'s payslip as paid?" class="fi-btn rounded-lg bg-primary-600 px-2 py-1 text-xs font-medium text-white">
                                            Mark Paid
                                        </button>
                                    </div>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="text-sm text-gray-500 dark:text-gray-400">No sealed payroll runs yet.</div>
    @endif
</x-filament-panels::page>
