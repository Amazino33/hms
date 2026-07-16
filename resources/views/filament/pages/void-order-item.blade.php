<x-filament-panels::page>
    <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 mb-4">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            This is not a return — no stock is reversed. Use this only for an item that is already gone
            (comp, guest complaint, spillage after serving). It removes the amount from the waiter's expected
            remittance and keeps a permanent reasoned record for reporting.
        </p>
    </div>

    <div class="space-y-4 pb-28">
        @if(! $this->selectedItem())
            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Find the order item</label>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search by product name…"
                    class="w-full px-4 py-3 min-h-[48px] border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" />

                @if(trim($search) !== '')
                    <div class="mt-3 divide-y divide-gray-100 dark:divide-gray-700 max-h-80 overflow-y-auto">
                        @forelse($this->searchResults() as $result)
                            <button type="button" wire:click="selectItem({{ $result['id'] }})"
                                class="w-full text-left py-3 px-2 min-h-[48px] touch-manipulation hover:bg-gray-50 dark:hover:bg-gray-700 rounded-lg">
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $result['label'] }}</span>
                            </button>
                        @empty
                            <p class="text-sm text-gray-400 py-3">No matching order item.</p>
                        @endforelse
                    </div>
                @endif
            </div>
        @else
            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700 flex items-center justify-between gap-3">
                <div>
                    <div class="text-xs font-bold uppercase text-gray-500 dark:text-gray-400">Voiding</div>
                    <div class="text-base font-bold text-gray-900 dark:text-white">
                        #{{ $this->selectedItem()->order_id }} — {{ $this->selectedItem()->product_name }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">On order: {{ $this->selectedItem()->quantity }}</div>
                </div>
                <button type="button" wire:click="clearSelection"
                    class="shrink-0 min-h-[48px] px-3 py-2 rounded-lg text-sm font-bold text-primary-600 touch-manipulation">Change</button>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700"
                x-data="{ quantity: @entangle('quantity') }">
                <x-mobile.stepper model="quantity" :min="1" :max="$this->selectedItem()->quantity" :integer="true" label="Quantity to void" />
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700"
                x-data="{ reasonCode: @entangle('reasonCode') }">
                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Reason</label>
                <x-mobile.chip-select model="reasonCode" :options="\App\Filament\Pages\VoidOrderItem::REASON_OPTIONS" />
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Notes</label>
                <textarea wire:model="notes" rows="3" placeholder="Optional detail"
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"></textarea>
            </div>
        @endif
    </div>

    @if($this->selectedItem())
        <x-mobile.sticky-cta-bar>
            <button type="button" wire:click="apply" wire:loading.attr="disabled" wire:target="apply"
                @if(!$reasonCode) disabled @endif
                class="w-full min-h-[48px] py-4 rounded-xl text-white text-lg font-bold touch-manipulation flex items-center justify-center gap-2
                    {{ $reasonCode ? 'bg-red-600 hover:bg-red-700' : 'bg-gray-400 cursor-not-allowed' }}">
                <span wire:loading.remove wire:target="apply">Void Item</span>
                <span wire:loading wire:target="apply">Voiding…</span>
            </button>
            @unless($reasonCode)
                <p class="text-center text-xs text-gray-500 dark:text-gray-400 mt-1.5">Choose a reason above first</p>
            @endunless
        </x-mobile.sticky-cta-bar>
    @endif
</x-filament-panels::page>
