<x-filament-panels::page>
    @php($shift = $this->currentShift())

    @if(! $shift)
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 max-w-md space-y-3">
            <h3 class="font-bold text-gray-900 dark:text-white">Start your shift</h3>
            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Starting till float</label>
            <input type="number" step="0.01" wire:model="startingFloat" placeholder="0.00"
                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" />
            <button type="button" wire:click="startShift" class="w-full px-4 py-3 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold">
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

            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 space-y-3">
                <h3 class="font-bold text-gray-900 dark:text-white">Declare end of shift</h3>
                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300">Cash counted in drawer</label>
                <input type="number" step="0.01" wire:model="declaredCash" placeholder="0.00"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" />
                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300">POS/transfer total</label>
                <input type="number" step="0.01" wire:model="declaredPos" placeholder="0.00"
                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" />
                <button type="button" wire:click="declareEnd" class="w-full px-4 py-3 rounded-lg bg-red-600 hover:bg-red-700 text-white font-bold">
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
