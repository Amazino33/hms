<x-filament-panels::page>
    <div class="space-y-6">
        <div class="bg-gradient-to-r from-amber-50 to-orange-50 dark:from-gray-800 dark:to-gray-900 rounded-lg p-6 border border-amber-200 dark:border-gray-700">
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Transfer Discrepancies</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                Short or rejected transfer lines opened during receiving — resolve by reversing the missing
                quantity back to the main store, or writing it off as genuinely missing.
            </p>
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
