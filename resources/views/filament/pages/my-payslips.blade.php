<x-filament-panels::page>
    @if ($lines->isEmpty())
        <div class="text-sm text-gray-500 dark:text-gray-400">No payslips yet.</div>
    @endif

    @foreach ($lines as $line)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="text-lg font-semibold">
                    {{ $line->run->period_start->format('M j') }} – {{ $line->run->period_end->format('M j, Y') }}
                </div>
                <span class="text-xs uppercase text-gray-500 dark:text-gray-400">{{ ucfirst(str_replace('_', ' ', $line->status)) }}</span>
            </div>

            <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Base</div>
                    <div class="font-medium">₦{{ number_format($line->base_amount, 2) }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Commission</div>
                    <div class="font-medium">₦{{ number_format($line->commission_amount, 2) }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Deductions</div>
                    <div class="font-medium">₦{{ number_format($line->deduction_amount, 2) }}</div>
                </div>
                <div>
                    <div class="text-gray-500 dark:text-gray-400">Net Pay</div>
                    <div class="font-semibold">₦{{ number_format($line->net_amount, 2) }}</div>
                </div>
            </div>

            @if ($line->deductions->isNotEmpty())
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    Deductions: @foreach ($line->deductions as $d) {{ $d->staffDebt?->reason }} (₦{{ number_format($d->amount, 2) }}){{ !$loop->last ? ',' : '' }} @endforeach
                </div>
            @endif

            @if ($line->status === 'paid')
                <div class="text-sm text-gray-600 dark:text-gray-300">
                    Paid via {{ ucfirst($line->payment_method) }}
                    @if ($line->payment_reference) · Ref: {{ $line->payment_reference }} @endif
                    on {{ $line->paid_at?->format('M j, Y g:i A') }}
                </div>

                <div class="flex flex-wrap items-end gap-2 pt-2">
                    <button type="button" wire:click="acknowledge({{ $line->id }})" wire:confirm="Confirm you received this payment as shown?" class="fi-btn rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white">
                        Confirm Receipt
                    </button>

                    <div class="flex flex-col gap-1">
                        <input type="text" wire:model="disputeReason.{{ $line->id }}" placeholder="What's wrong? (required to dispute)" class="fi-input w-64 rounded-lg border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-800">
                        <input type="number" step="0.01" min="0" wire:model="disputeReportedAmount.{{ $line->id }}" placeholder="Amount you actually received (optional)" class="fi-input w-64 rounded-lg border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-800">
                    </div>
                    <button type="button" wire:click="dispute({{ $line->id }})" class="fi-btn rounded-lg bg-danger-600 px-3 py-1.5 text-sm font-medium text-white">
                        I Received Less
                    </button>
                </div>
            @elseif ($line->status === 'acknowledged')
                <div class="text-sm text-success-600 dark:text-success-400">
                    You confirmed receipt on {{ $line->acknowledged_at?->format('M j, Y g:i A') }}.
                </div>
            @elseif ($line->status === 'disputed')
                <div class="text-sm text-danger-600 dark:text-danger-400">
                    Dispute recorded — waiting on a manager: {{ $line->dispute_reason }}
                </div>
            @elseif ($line->status === 'closed_no_ack')
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    Closed by {{ $line->closedBy?->name ?? 'a manager' }}: {{ $line->closed_reason }}
                </div>
            @else
                <div class="text-sm text-gray-500 dark:text-gray-400">Awaiting payment.</div>
            @endif
        </div>
    @endforeach
</x-filament-panels::page>
