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

            @if($this->ownerTakeNotes()->isNotEmpty())
                <div class="bg-amber-50 dark:bg-amber-900/20 rounded-lg p-4 border border-amber-200 dark:border-amber-800">
                    <div class="font-bold text-amber-800 dark:text-amber-300">Oga's Take — noted by {{ $shift->user?->name }}</div>
                    <ul class="mt-2 space-y-1">
                        @foreach($this->ownerTakeNotes() as $note)
                            <li class="text-sm text-amber-900 dark:text-amber-200">
                                @if($note->amount) <span class="font-bold">₦{{ number_format($note->amount, 2) }}</span> — @endif
                                {{ $note->description }}
                                <span class="text-amber-700 dark:text-amber-400">({{ $note->created_at->format('g:ia') }})</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                <div class="flex justify-between items-center mb-2">
                    <h3 class="font-bold text-gray-900 dark:text-white">Debt</h3>
                    @if(! $showDebtForm)
                        <button type="button" wire:click="openDebtForm" class="text-xs text-primary-600 font-bold">+ Record Debt</button>
                    @endif
                </div>

                @forelse($this->staffDebts() as $debt)
                    <div class="flex justify-between items-center text-sm border-t border-gray-100 dark:border-gray-700 py-2">
                        <div>
                            <div class="text-gray-900 dark:text-white font-bold">₦{{ number_format($debt->amount, 2) }} — {{ ucfirst(str_replace('_', ' ', $debt->reason)) }}</div>
                            @if($debt->notes)
                                <div class="text-xs text-gray-500">{{ $debt->notes }}</div>
                            @endif
                        </div>
                        <span class="text-xs font-bold {{ $debt->status === 'open' ? 'text-red-500' : 'text-amber-500' }}">{{ ucfirst(str_replace('_', ' ', $debt->status)) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">No open debts for this staff member.</p>
                @endforelse

                @if($showDebtForm)
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700 space-y-3" x-data="{ debtAmount: @entangle('debtAmount') }">
                        <x-mobile.numeric-pad model="debtAmount" :currency="true" label="Amount" />
                        <textarea wire:model="debtNotes" rows="2" placeholder="What happened (optional)"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-sm"></textarea>
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" wire:click="cancelDebtForm" class="min-h-[44px] px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-bold text-sm">
                                Cancel
                            </button>
                            <button type="button" wire:click="recordDebt" class="min-h-[44px] px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white font-bold text-sm">
                                Save Debt
                            </button>
                        </div>
                    </div>
                @endif
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

            @if($this->usesChannelSplit())
                {{-- Bar/kitchen split: this waiter served both destinations this
                     shift, so cash and POS are confirmed independently per
                     destination instead of one combined figure. --}}
                @foreach($this->activeDestinations() as $destination)
                    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                        <h3 class="font-bold text-gray-900 dark:text-white mb-3">{{ ucfirst($destination) }}</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @php($cashRow = $this->channelConfirmation($destination, 'cash'))
                            <div class="space-y-3">
                                <h4 class="font-bold text-sm text-gray-700 dark:text-gray-300">Cash</h4>
                                @if($cashRow && $cashRow->confirmed_at)
                                    <div class="text-sm text-gray-700 dark:text-gray-300">
                                        Counted: <span class="font-bold">₦{{ number_format($cashRow->confirmed_amount, 2) }}</span>
                                        by {{ $cashRow->confirmedBy?->name }} at {{ $cashRow->confirmed_at->format('g:ia') }}
                                    </div>
                                    <div class="text-sm text-gray-500">Expected: ₦{{ number_format($cashRow->expected_amount, 2) }}</div>
                                @else
                                    <p class="text-xs text-gray-500">Count the physical cash yourself before entering a figure.</p>
                                    <div x-data="{ amount: @entangle($destination.'CashAmount') }" class="space-y-3">
                                        <x-mobile.numeric-pad model="amount" :currency="true" label="Amount counted" />
                                        <button type="button" wire:click="confirmChannel('{{ $destination }}', 'cash')" class="w-full min-h-[48px] px-4 py-3 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold touch-manipulation">
                                            Confirm {{ ucfirst($destination) }} Cash
                                        </button>
                                    </div>
                                @endif
                            </div>

                            @php($posRow = $this->channelConfirmation($destination, 'pos'))
                            <div class="space-y-3">
                                <h4 class="font-bold text-sm text-gray-700 dark:text-gray-300">POS Machine</h4>
                                @if($posRow && $posRow->confirmed_at)
                                    <div class="text-sm text-gray-700 dark:text-gray-300">
                                        Confirmed: <span class="font-bold">₦{{ number_format($posRow->confirmed_amount, 2) }}</span>
                                        by {{ $posRow->confirmedBy?->name }} at {{ $posRow->confirmed_at->format('g:ia') }}
                                    </div>
                                    @if($posRow->flagged)
                                        <div class="text-sm text-amber-600 font-bold">Mismatch flagged — awaiting supervisor ruling.</div>
                                    @endif
                                @else
                                    <div class="text-sm text-gray-500">System total: <span class="font-bold">₦{{ number_format($this->expectedForDestination($destination, 'pos'), 2) }}</span></div>
                                    <div x-data="{ amount: @entangle($destination.'PosAmount') }" class="space-y-3">
                                        <x-mobile.numeric-pad model="amount" :currency="true" label="Machine batch total" />
                                        <button type="button" wire:click="confirmChannel('{{ $destination }}', 'pos')" class="w-full min-h-[48px] px-4 py-3 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold touch-manipulation">
                                            Confirm {{ ucfirst($destination) }} POS
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            @else
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
                            <div x-data="{ cashierCountedCash: @entangle('cashierCountedCash') }" class="space-y-3">
                                <x-mobile.numeric-pad model="cashierCountedCash" :currency="true" label="Amount counted" />
                                <button type="button" wire:click="confirmCash" class="w-full min-h-[48px] px-4 py-3 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold touch-manipulation">
                                    Confirm Cash
                                </button>
                            </div>
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
                            <div x-data="{ posMachineAmount: @entangle('posMachineAmount') }" class="space-y-3">
                                <x-mobile.numeric-pad model="posMachineAmount" :currency="true" label="Machine batch total" />
                                <button type="button" wire:click="confirmPos" class="w-full min-h-[48px] px-4 py-3 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold touch-manipulation">
                                    Confirm POS Total
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

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
