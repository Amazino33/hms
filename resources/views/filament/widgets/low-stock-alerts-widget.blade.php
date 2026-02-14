<x-filament-widgets::widget>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                ⚠️ Low Stock Alerts
            </h3>
            <span class="text-sm text-gray-500 dark:text-gray-400">
                Ingredients ≤ 10 units
            </span>
        </div>

        @if(empty($this->getLowStockAlerts()))
            <div class="text-center py-8">
                <div class="w-16 h-16 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                </div>
                <p class="text-gray-600 dark:text-gray-400">All ingredients are well stocked!</p>
            </div>
        @else
            <div class="space-y-4">
                @foreach($this->getLowStockAlerts() as $alert)
                    <div class="border border-orange-200 dark:border-orange-600 rounded-lg p-4 bg-orange-50 dark:bg-orange-950/50">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <h4 class="font-medium text-gray-900 dark:text-gray-100">
                                    {{ $alert['ingredient']->name }}
                                </h4>
                                <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">
                                    Current stock: <span class="font-medium text-orange-600 dark:text-orange-400">{{ $alert['ingredient']->quantity }}</span> {{ $alert['ingredient']->unit_name }}
                                </p>
                                @if(!empty($alert['affected_menu_items']))
                                    <div class="mt-2">
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Used in menu items:</p>
                                        <div class="flex flex-wrap gap-1">
                                            @foreach($alert['affected_menu_items'] as $menuItem)
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-orange-100 dark:bg-orange-900/50 text-orange-800 dark:text-orange-200">
                                                    {{ $menuItem->name }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                            <div class="ml-4">
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-orange-100 dark:bg-orange-900/50 text-orange-800 dark:text-orange-200">
                                    Low Stock
                                </span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-widgets::widget>