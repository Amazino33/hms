<x-filament-panels::page>
    @if($this->myOpenSession)
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-amber-300 dark:border-amber-700 p-6">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">You already have a count in progress</h3>
            <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                Counting: <span class="font-bold">{{ $this->myOpenSession->item_scope === 'ingredient' ? 'Ingredients' : 'Products' }}</span>
                &middot;
                Status: <span class="font-bold">{{ ucwords(str_replace('_', ' ', $this->myOpenSession->status)) }}</span>
            </p>
            <button wire:click="goToOpenSession" class="px-4 py-3 rounded-lg bg-amber-500 hover:bg-amber-600 text-white font-bold kiosk-tap kiosk-primary-pulse">
                Continue Counting
            </button>
        </div>
    @else
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 max-w-xl">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Start a Store Count</h3>
            <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                You'll count alone — nobody else needs to be there. Expected quantities stay hidden the whole way
                through; you'll only see what you've entered. When you submit, confirm with your own PIN and the
                count seals immediately — any shortage or overage then goes to the super-admin to review.
            </p>
            <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                Products and ingredients are counted separately — pick one to start.
            </p>
            <div class="flex flex-col sm:flex-row gap-3">
                <button wire:click="startCount('product')" class="flex-1 px-4 py-3 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold kiosk-tap kiosk-primary-pulse">
                    Count Products
                </button>
                <button wire:click="startCount('ingredient')" class="flex-1 px-4 py-3 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-bold kiosk-tap kiosk-primary-pulse">
                    Count Ingredients
                </button>
            </div>
        </div>
    @endif
</x-filament-panels::page>
