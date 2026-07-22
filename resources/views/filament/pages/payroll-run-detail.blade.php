<x-filament-panels::page>
    @php($run = $this->run)

    @if ($run)
        <div class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 space-y-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div class="text-lg font-semibold">
                        {{ $run->period_start->format('M j') }} – {{ $run->period_end->format('M j, Y') }}
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        Prepared by {{ $run->preparer?->name ?? '—' }}
                        @if ($run->sealed_at)
                            · Sealed {{ $run->sealed_at->format('M j, Y g:i A') }}
                        @endif
                    </div>
                </div>
                <span @class([
                    'fi-badge inline-flex items-center rounded-md px-2 py-1 text-xs font-medium',
                    'bg-gray-100 text-gray-700 dark:bg-gray-500/20 dark:text-gray-300' => $run->status === 'draft',
                    'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-300' => $run->status === 'sealed',
                    'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-300' => $run->status === 'closed',
                    'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-300' => $run->status === 'voided',
                ])>
                    {{ ucfirst($run->status) }}
                </span>
            </div>

            @if ($run->status === 'voided')
                <div class="rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-500/10 dark:text-danger-300">
                    Voided by {{ $run->voider?->name ?? '—' }} on {{ $run->voided_at?->format('M j, Y g:i A') }} — {{ $run->void_reason }}
                    @if ($run->supersededBy)
                        <a href="/admin/payroll-run-detail?run_id={{ $run->supersededBy->id }}" class="ml-2 underline font-medium">View the reissued run →</a>
                    @endif
                </div>
            @endif

            @if ($run->supersedes)
                <div class="rounded-lg bg-gray-50 p-3 text-sm text-gray-600 dark:bg-gray-500/10 dark:text-gray-300">
                    Reissued to correct <a href="/admin/payroll-run-detail?run_id={{ $run->supersedes->id }}" class="underline font-medium">run #{{ $run->supersedes->id }}</a>.
                </div>
            @endif
        </div>

        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5 text-left text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">Staff</th>
                        <th class="px-4 py-3 text-right">Base</th>
                        <th class="px-4 py-3 text-right">Commission</th>
                        <th class="px-4 py-3 text-right">Gross</th>
                        <th class="px-4 py-3 text-right">Deduction</th>
                        <th class="px-4 py-3 text-right">Net</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($run->lines->sortBy(fn ($l) => $l->user?->name) as $line)
                        <tr class="align-top">
                            <td class="px-4 py-3 font-medium">{{ $line->user?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">₦{{ number_format($line->base_amount, 2) }}</td>
                            <td class="px-4 py-3 text-right">₦{{ number_format($line->commission_amount, 2) }}</td>
                            <td class="px-4 py-3 text-right">₦{{ number_format($line->gross_amount, 2) }}</td>
                            <td class="px-4 py-3 text-right">
                                <div>₦{{ number_format($line->deduction_amount, 2) }}</div>
                                @if ($line->deductions->isNotEmpty())
                                    <div class="mt-1 space-y-1">
                                        @foreach ($line->deductions as $deduction)
                                            <div class="flex items-center justify-end gap-1 text-xs text-gray-500 dark:text-gray-400">
                                                <span>{{ $deduction->staffDebt?->reason }} — ₦{{ number_format($deduction->amount, 2) }}</span>
                                                @if ($this->canEditDeductions())
                                                    <button type="button" wire:click="removeDeduction({{ $deduction->id }})" class="text-danger-600 hover:underline">✕</button>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                @if ($this->canEditDeductions())
                                    <div class="mt-2 flex flex-col gap-1 items-end">
                                        <select wire:model="deductionDebtId.{{ $line->id }}" class="fi-select-input rounded-lg border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-800">
                                            <option value="">Add deduction from debt…</option>
                                            @foreach ($this->openDebtsFor($line->user_id) as $debt)
                                                <option value="{{ $debt->id }}">{{ $debt->reason }} — outstanding ₦{{ number_format($debt->remainingBalance(), 2) }}</option>
                                            @endforeach
                                        </select>
                                        <div class="flex gap-1">
                                            <input type="number" step="0.01" min="0" wire:model="deductionAmount.{{ $line->id }}" placeholder="Amount" class="fi-input w-24 rounded-lg border-gray-300 text-xs dark:border-gray-600 dark:bg-gray-800">
                                            <button type="button" wire:click="addDeduction({{ $line->id }})" class="fi-btn rounded-lg bg-primary-600 px-2 py-1 text-xs font-medium text-white">Add</button>
                                        </div>
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-semibold">₦{{ number_format($line->net_amount, 2) }}</td>
                            <td class="px-4 py-3">
                                <span class="text-xs">{{ ucfirst(str_replace('_', ' ', $line->status)) }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($this->canEditDeductions())
                <button type="button" wire:click="refreshDraft" class="fi-btn rounded-lg bg-gray-100 px-4 py-2 text-sm font-medium dark:bg-white/5">
                    Recompute Figures
                </button>
                <button type="button" wire:click="sealRun" wire:confirm="Sealing freezes every figure on this run and makes it visible to the CEO for payment. Continue?" class="fi-btn rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white">
                    Seal Run
                </button>
            @endif

            @if ($this->canVoid())
                <div class="flex items-center gap-2">
                    <input type="text" wire:model="voidReason" placeholder="Reason for voiding" class="fi-input rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                    <button type="button" wire:click="voidAndReissue" wire:confirm="This voids the run and drafts a fresh one for the same period. Continue?" class="fi-btn rounded-lg bg-danger-600 px-4 py-2 text-sm font-medium text-white">
                        Void &amp; Reissue
                    </button>
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
