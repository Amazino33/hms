<x-filament-panels::page>
    @php($session = $this->session())

    <div class="space-y-6 max-w-2xl">
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <div class="text-sm text-gray-500 dark:text-gray-400">Opened {{ $session->opened_at->format('M j, g:ia') }}</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-white mt-1">₦{{ number_format($this->accruedCash(), 2) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">Accrued cash (settlements confirmed + drops received, minus outflows)</div>
        </div>

        @if($session->status === 'open')
            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 space-y-3"
                x-data="{ outflowAmount: @entangle('outflowAmount'), outflowType: @entangle('outflowType') }">
                <h3 class="font-bold text-gray-900 dark:text-white">Log an outflow</h3>
                <x-mobile.numeric-pad model="outflowAmount" :currency="true" label="Amount" />
                <x-mobile.chip-select model="outflowType" :options="['deposit' => 'Bank deposit', 'handover' => 'Handover']" />
                <input type="text" wire:model="outflowNote" placeholder="Note"
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white min-h-[48px]" />
                <button type="button" wire:click="logOutflow" class="w-full min-h-[48px] px-4 py-3 rounded-lg bg-gray-700 hover:bg-gray-800 text-white font-bold touch-manipulation">
                    Log Outflow
                </button>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 space-y-3"
                x-data="{ declaredClosingCash: @entangle('declaredClosingCash') }">
                <h3 class="font-bold text-gray-900 dark:text-white">Declare close</h3>
                <x-mobile.numeric-pad model="declaredClosingCash" :currency="true" label="Cash counted at close" />
                <button type="button" wire:click="declareClose" class="w-full min-h-[48px] px-4 py-3 rounded-lg bg-red-600 hover:bg-red-700 text-white font-bold touch-manipulation">
                    Declare Close
                </button>
            </div>
        @elseif($session->status === 'pending_supervisor')
            <div class="bg-amber-50 dark:bg-amber-900/20 rounded-lg p-4 border border-amber-200 dark:border-amber-800">
                <div class="font-bold text-amber-700 dark:text-amber-400">Awaiting supervisor close-out</div>
                <p class="text-sm text-amber-800 dark:text-amber-300 mt-1">
                    You declared ₦{{ number_format($session->declared_closing_cash, 2) }}. A supervisor needs to independently count and confirm before this session closes.
                </p>
            </div>
        @endif

        @if($session->outflows->isNotEmpty())
            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                <h3 class="font-bold text-gray-900 dark:text-white mb-2">Outflows this session</h3>
                <ul class="text-sm space-y-1">
                    @foreach($session->outflows as $outflow)
                        <li class="text-gray-700 dark:text-gray-300">₦{{ number_format($outflow->amount, 2) }} — {{ ucfirst($outflow->type) }} — {{ $outflow->note }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-filament-panels::page>
