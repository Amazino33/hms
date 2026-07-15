<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid grid-cols-3 gap-4">
            <a href="/admin/transfer-queue" class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 text-center">
                <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $unverifiedTransferCount }}</div>
                <div class="text-xs text-gray-500">Unverified transfers</div>
            </a>
            <a href="/admin/pending-cash-drops" class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 text-center">
                <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $pendingDropCount }}</div>
                <div class="text-xs text-gray-500">Pending cash drops</div>
            </a>
            <a href="/admin/shift-management" class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 text-center">
                <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $awaitingCashierCount }}</div>
                <div class="text-xs text-gray-500">Settlements awaiting cashier</div>
            </a>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <h3 class="font-bold text-gray-900 dark:text-white mb-3">Open flags</h3>

            @forelse($flaggedTransfers as $payment)
                <div class="flex justify-between items-center border-t border-gray-100 dark:border-gray-700 py-2">
                    <div>
                        <div class="text-sm font-bold text-gray-900 dark:text-white">Transfer ₦{{ number_format($payment->amount, 2) }} — {{ $payment->user?->name }}</div>
                        <div class="text-xs text-gray-500">{{ ucfirst(str_replace('_', ' ', $payment->flag_reason)) }} · flagged by {{ $payment->flaggedBy?->name }}</div>
                    </div>
                    <button type="button" wire:click="openTransferRuling({{ $payment->id }})" class="px-3 py-1 rounded-lg bg-primary-600 text-white font-bold text-xs">Rule</button>
                </div>
            @empty
                <p class="text-sm text-gray-400">No flagged transfers.</p>
            @endforelse

            @forelse($flaggedPos as $shift)
                <div class="flex justify-between items-center border-t border-gray-100 dark:border-gray-700 py-2">
                    <div>
                        <div class="text-sm font-bold text-gray-900 dark:text-white">POS-machine mismatch — {{ $shift->user?->name }}</div>
                        <div class="text-xs text-gray-500">Machine: ₦{{ number_format($shift->pos_machine_confirmed_amount, 2) }}</div>
                    </div>
                    <button type="button" wire:click="openPosRuling({{ $shift->id }})" class="px-3 py-1 rounded-lg bg-primary-600 text-white font-bold text-xs">Rule</button>
                </div>
            @empty
            @endforelse
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <h3 class="font-bold text-gray-900 dark:text-white mb-3">Cashier sessions awaiting close-out</h3>
            @forelse($pendingCashierSessions as $session)
                <div class="flex justify-between items-center border-t border-gray-100 dark:border-gray-700 py-2">
                    <div class="text-sm font-bold text-gray-900 dark:text-white">{{ $session->user?->name }} — declared at {{ $session->declared_at->format('g:ia') }}</div>
                    <button type="button" wire:click="openSessionClose({{ $session->id }})" class="px-3 py-1 rounded-lg bg-red-600 text-white font-bold text-xs">Confirm Close</button>
                </div>
            @empty
                <p class="text-sm text-gray-400">Nothing awaiting close-out.</p>
            @endforelse
        </div>
    </div>

    @if($rulingTransferId || $rulingShiftId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4" wire:click.self="closeRuling">
            <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md space-y-4">
                <h3 class="font-bold text-lg text-gray-900 dark:text-white">Rule on this flag</h3>
                <textarea wire:model="rulingNote" rows="3" placeholder="Note (required)"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"></textarea>
                <div class="grid grid-cols-3 gap-2">
                    @if($rulingTransferId)
                        <button type="button" wire:click="ruleTransfer('late_verify')" class="px-3 py-2 rounded-lg bg-emerald-600 text-white font-bold text-xs">Verified Late</button>
                        <button type="button" wire:click="ruleTransfer('charge')" class="px-3 py-2 rounded-lg bg-red-600 text-white font-bold text-xs">Charge to Staff</button>
                        <button type="button" wire:click="ruleTransfer('void')" class="px-3 py-2 rounded-lg bg-gray-500 text-white font-bold text-xs">Void</button>
                    @else
                        <button type="button" wire:click="rulePos('late_verify')" class="px-3 py-2 rounded-lg bg-emerald-600 text-white font-bold text-xs">Verified Late</button>
                        <button type="button" wire:click="rulePos('charge')" class="px-3 py-2 rounded-lg bg-red-600 text-white font-bold text-xs">Charge to Staff</button>
                        <button type="button" wire:click="rulePos('void')" class="px-3 py-2 rounded-lg bg-gray-500 text-white font-bold text-xs">Void</button>
                    @endif
                </div>
                <button type="button" wire:click="closeRuling" class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-bold">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    @if($closingSessionId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4" wire:click.self="$set('closingSessionId', null)">
            <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md space-y-4">
                <h3 class="font-bold text-lg text-gray-900 dark:text-white">Confirm cashier close-out</h3>
                <p class="text-xs text-gray-500">Count the physical cash yourself before entering a figure — her declared closing amount is not shown until after you confirm.</p>
                <input type="number" step="0.01" wire:model="supervisorCountedCash" placeholder="Amount counted"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" />
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="$set('closingSessionId', null)" class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-bold">
                        Cancel
                    </button>
                    <button type="button" wire:click="confirmSessionClose" class="px-4 py-2 rounded-lg bg-red-600 text-white font-bold">
                        Confirm Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
