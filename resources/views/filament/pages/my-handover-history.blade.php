<x-filament-panels::page>
    <div class="space-y-6">
        <div class="bg-white dark:bg-gray-900 rounded-lg p-6 border border-gray-200 dark:border-gray-700">
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">My Handover History</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                Every handover you've been part of, as outgoing or incoming custodian. Open one for the full
                comparison and a PDF download.
            </p>
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
