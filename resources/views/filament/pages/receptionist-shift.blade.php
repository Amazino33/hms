<x-filament-panels::page>
    @php($shift = $this->currentShift())

    @if(! $shift)
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 max-w-md space-y-3"
            x-data="{ startingFloat: @entangle('startingFloat') }">
            <h3 class="font-bold text-gray-900 dark:text-white">Start your shift</h3>
            <x-mobile.numeric-pad model="startingFloat" :currency="true" label="Starting till float" />
            <button type="button" wire:click="startShift" class="w-full min-h-[48px] px-4 py-3 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold touch-manipulation">
                Start Shift
            </button>
        </div>
    @elseif($shift->status === 'active')
        <div class="space-y-4 max-w-md">
            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                <div class="text-sm text-gray-500 dark:text-gray-400">Started {{ $shift->started_at->format('M j, g:ia') }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Starting float: ₦{{ number_format($shift->starting_float ?? 0, 2) }}</div>
                <div class="mt-2 grid grid-cols-2 gap-3">
                    <div>
                        <div class="text-xs text-gray-500">Expected cash so far</div>
                        <div class="text-lg font-bold text-gray-900 dark:text-white">₦{{ number_format($this->expectedCashSoFar(), 2) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Expected POS/transfer so far</div>
                        <div class="text-lg font-bold text-gray-900 dark:text-white">₦{{ number_format($this->expectedPosSoFar(), 2) }}</div>
                    </div>
                </div>
            </div>

            <div class="bg-amber-50 dark:bg-amber-900/20 rounded-lg p-4 border border-amber-200 dark:border-amber-800 space-y-3"
                x-data="{ ownerTakeAmount: @entangle('ownerTakeAmount') }">
                <h3 class="font-bold text-gray-900 dark:text-white">Record Oga's Take</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    This is just a note for the cashier and the owner — it doesn't change anything about your shift.
                </p>
                <x-mobile.numeric-pad model="ownerTakeAmount" :currency="true" label="Amount (if known)" />
                <textarea wire:model="ownerTakeDescription" rows="3" placeholder="e.g. Oga took cash from the drawer"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-800 dark:text-white focus:ring-2 focus:ring-amber-500 focus:border-transparent"></textarea>
                <button type="button" wire:click="recordOwnerTake" class="w-full min-h-[48px] px-4 py-3 rounded-lg bg-amber-500 hover:bg-amber-600 text-white font-bold touch-manipulation">
                    Save Note
                </button>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 space-y-3"
                x-data="{ declaredCash: @entangle('declaredCash'), declaredPos: @entangle('declaredPos') }">
                <h3 class="font-bold text-gray-900 dark:text-white">Declare end of shift</h3>
                <x-mobile.numeric-pad model="declaredCash" :currency="true" label="Cash counted in drawer" />
                <x-mobile.numeric-pad model="declaredPos" :currency="true" label="POS/transfer total" />
                <button type="button" wire:click="declareEnd" class="w-full min-h-[48px] px-4 py-3 rounded-lg bg-red-600 hover:bg-red-700 text-white font-bold touch-manipulation">
                    Declare End of Shift
                </button>
            </div>
        </div>
    @else
        <div class="bg-amber-50 dark:bg-amber-900/20 rounded-lg p-4 border border-amber-200 dark:border-amber-800 max-w-md">
            <div class="font-bold text-amber-700 dark:text-amber-400">Awaiting cashier confirmation</div>
            <p class="text-sm text-amber-800 dark:text-amber-300 mt-1">
                You declared ₦{{ number_format($shift->declared_cash, 2) }} cash and ₦{{ number_format($shift->declared_pos, 2) }} POS/transfer.
                A cashier needs to confirm this (or a supervisor, as fallback) before your shift fully closes.
            </p>
        </div>
    @endif
</x-filament-panels::page>
