<x-filament-panels::page>
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 border border-gray-200 dark:border-gray-700">
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
            This is not a return — no stock is reversed. Use this only for an item that is already gone
            (comp, guest complaint, spillage after serving). It removes the amount from the waiter's expected
            remittance and keeps a permanent reasoned record for reporting.
        </p>

        <form wire:submit="apply">
            {{ $this->form }}

            <div class="mt-6 flex justify-end">
                <x-filament::button type="submit" color="danger" icon="heroicon-o-receipt-refund">
                    Void Item
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
