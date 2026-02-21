<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Header -->
        <div class="bg-gradient-to-r from-slate-50 to-gray-100 dark:from-gray-800 dark:to-gray-900 rounded-lg p-6 border border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Company Settings</h2>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">These details appear on printed receipts.</p>
                </div>
                <div class="text-5xl">🏢</div>
            </div>
        </div>

        <!-- Form Card -->
        <div class="bg-white dark:bg-gray-800 rounded-lg p-6 border border-gray-200 dark:border-gray-700">
            <form wire:submit="save">
                {{ $this->form }}

                <div class="mt-6 flex justify-end">
                    <x-filament::button type="submit" color="primary" icon="heroicon-o-check">
                        Save Settings
                    </x-filament::button>
                </div>
            </form>
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
