<x-filament-panels::page>
    @php($shift = $this->shift())

    @if($shift)
        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                <div class="font-bold text-lg text-gray-900 dark:text-white">{{ $shift->user?->name }} — {{ ucfirst($shift->type) }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $shift->started_at?->format('M j, g:ia') }} &rarr; {{ $shift->ended_at?->format('M j, g:ia') }}
                    · Status: <span class="font-semibold">{{ ucfirst(str_replace('_', ' ', $shift->status)) }}</span>
                </div>
            </div>

            @if($shift->hasOpenFlag())
                <div class="bg-red-50 dark:bg-red-900/20 rounded-lg p-4 border border-red-200 dark:border-red-800">
                    <div class="font-bold text-red-700 dark:text-red-400">Blocked — awaiting supervisor ruling</div>
                    <p class="text-sm text-red-800 dark:text-red-300 mt-1">
                        This settlement cannot confirm until every open flag (a disputed transfer and/or a POS-machine mismatch) is ruled on.
                    </p>
                </div>
            @endif

            @if($shift->status === 'confirmed')
                <div class="bg-emerald-50 dark:bg-emerald-900/20 rounded-lg p-4 border border-emerald-200 dark:border-emerald-800">
                    <div class="font-bold text-emerald-700 dark:text-emerald-400">Confirmed</div>
                    <div class="text-sm text-emerald-800 dark:text-emerald-300 mt-1">
                        Variance: ₦{{ number_format($shift->cash_variance, 2) }}
                        @if((float) $shift->cash_variance < 0) (shortfall) @elseif((float) $shift->cash_variance > 0) (surplus) @endif
                    </div>
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                {{-- Cash channel — blind: nothing about the staff's declared
                     figure appears anywhere in this block until AFTER
                     cash_confirmed_at is set. --}}
                <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 space-y-3">
                    <h3 class="font-bold text-gray-900 dark:text-white">Cash</h3>

                    @if($shift->cash_confirmed_at)
                        <div class="text-sm text-gray-700 dark:text-gray-300">
                            Cashier counted: <span class="font-bold">₦{{ number_format($shift->cashier_counted_cash, 2) }}</span>
                            by {{ $shift->cashConfirmedBy?->name }} at {{ $shift->cash_confirmed_at->format('g:ia') }}
                        </div>
                        <div class="text-sm text-gray-500">Staff declared: ₦{{ number_format($shift->declared_cash, 2) }}</div>
                        <div class="text-sm text-gray-500">Expected: ₦{{ number_format($this->expectedCash(), 2) }}</div>
                    @else
                        <p class="text-xs text-gray-500">Count the physical cash yourself before entering a figure — the staff member's own declaration is not shown until after you confirm.</p>
                        <input type="number" step="0.01" wire:model="cashierCountedCash" placeholder="Amount counted"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" />
                        <button type="button" wire:click="confirmCash" class="w-full px-4 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold">
                            Confirm Cash
                        </button>
                    @endif
                </div>

                {{-- POS machine channel — not blind: the system total is
                     shown upfront, she checks it against the physical
                     terminal's batch. --}}
                <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 space-y-3">
                    <h3 class="font-bold text-gray-900 dark:text-white">POS Machine</h3>

                    @if($shift->pos_confirmed_at)
                        <div class="text-sm text-gray-700 dark:text-gray-300">
                            Confirmed: <span class="font-bold">₦{{ number_format($shift->pos_machine_confirmed_amount, 2) }}</span>
                            by {{ $shift->posConfirmedBy?->name }} at {{ $shift->pos_confirmed_at->format('g:ia') }}
                        </div>
                        @if($shift->pos_flagged)
                            <div class="text-sm text-amber-600 font-bold">Mismatch flagged — awaiting supervisor ruling.</div>
                        @endif
                    @else
                        <div class="text-sm text-gray-500">System total: <span class="font-bold">₦{{ number_format($this->expectedPosMachine(), 2) }}</span></div>
                        <input type="number" step="0.01" wire:model="posMachineAmount" placeholder="Machine batch total"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" />
                        <button type="button" wire:click="confirmPos" class="w-full px-4 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold">
                            Confirm POS Total
                        </button>
                    @endif
                </div>
            </div>

            @php($transferSummary = $this->transferSummary())
            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                <h3 class="font-bold text-gray-900 dark:text-white mb-2">Transfers</h3>
                @if($transferSummary['total'] === 0)
                    <p class="text-sm text-gray-500">No transfer payments on this settlement.</p>
                @else
                    <p class="text-sm text-gray-700 dark:text-gray-300">
                        {{ $transferSummary['resolved'] }} of {{ $transferSummary['total'] }} resolved
                        @if($transferSummary['complete'])
                            <span class="text-emerald-600 font-bold">— complete</span>
                        @else
                            <span class="text-amber-600 font-bold">— pending</span>
                        @endif
                    </p>
                    @if($shift->type !== 'receptionist')
                        <a href="/admin/transfer-queue" class="text-xs text-primary-600 font-bold">Go to Transfer Queue &rarr;</a>
                    @else
                        <a href="/admin/transfer-verification" class="text-xs text-primary-600 font-bold">Go to Transfer Verification &rarr;</a>
                    @endif
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>
