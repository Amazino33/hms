<x-filament-panels::page>
    <div class="w-full bg-white dark:bg-gray-900 p-4 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
        {{ $this->form }}
    </div>

    <div class="flex flex-wrap -mx-2">
        
        <div class="w-full sm:w-1/2 xl:w-1/4 px-2 mb-4">
            <div class="bg-green-50 dark:bg-green-900/20 p-6 rounded-2xl border border-green-200 dark:border-green-800 shadow-sm h-full text-center">
                <div class="flex items-center mb-3 gap-3">
                    <div class="p-2 bg-green-100 dark:bg-green-800 rounded-lg text-green-600 dark:text-green-300 mr-4">
                        <x-heroicon-m-banknotes class="w-6 h-6" />
                    </div>
                    <span class="text-xs font-bold text-green-800 dark:text-green-300 uppercase tracking-wider">Cash in Drawer</span>
                </div>
                <div class="text-3xl font-black text-green-700 dark:text-green-400 truncate">
                    ₦{{ number_format($cashHand) }}
                </div>
                <div class="text-xs text-green-600 dark:text-green-500 mt-2 font-medium">
                    Physical cash collected
                </div>
            </div>
        </div>

        <div class="w-full sm:w-1/2 xl:w-1/4 px-2 mb-4">
            <div class="bg-blue-50 dark:bg-blue-900/20 p-6 rounded-2xl border border-blue-200 dark:border-blue-800 shadow-sm h-full text-center">
                <div class="flex items-center mb-3 gap-3">
                    <div class="p-2 bg-blue-100 dark:bg-blue-800 rounded-lg text-blue-600 dark:text-blue-300 mr-3">
                        <x-heroicon-m-credit-card class="w-6 h-6" />
                    </div>
                    <span class="text-xs font-bold text-blue-800 dark:text-blue-300 uppercase tracking-wider">Bank / POS</span>
                </div>
                <div class="text-3xl font-black text-blue-700 dark:text-blue-400 truncate">
                    ₦{{ number_format($posBank) }}
                </div>
                <div class="text-xs text-blue-600 dark:text-blue-500 mt-2 font-medium">
                    Digital transactions
                </div>
            </div>
        </div>

        <div class="w-full sm:w-1/2 xl:w-1/4 px-2 mb-4">
            <div class="bg-red-50 dark:bg-red-900/20 p-6 rounded-2xl border border-red-200 dark:border-red-800 shadow-sm h-full text-center">
                <div class="flex items-center mb-3 gap-3">
                    <div class="p-2 bg-red-100 dark:bg-red-800 rounded-lg text-red-600 dark:text-red-300 mr-3">
                        <x-heroicon-m-exclamation-triangle class="w-6 h-6" />
                    </div>
                    <span class="text-xs font-bold text-red-800 dark:text-red-300 uppercase tracking-wider">Total Debt</span>
                </div>
                <div class="text-3xl font-black text-red-700 dark:text-red-400 truncate">
                    ₦{{ number_format($totalDebt) }}
                </div>
                <div class="text-xs text-red-600 dark:text-red-500 mt-2 font-medium">
                    Outstanding balance
                </div>
            </div>
        </div>

        <div class="w-full sm:w-1/2 xl:w-1/4 px-2 mb-4">
            <div class="bg-gray-50 dark:bg-gray-800 p-6 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm h-full text-center">
                <div class="flex items-center mb-3 gap-3">
                    <div class="p-2 bg-gray-200 dark:bg-gray-700 rounded-lg text-gray-600 dark:text-gray-300 mr-3">
                        <x-heroicon-m-presentation-chart-line class="w-6 h-6" />
                    </div>
                    <span class="text-xs font-bold text-gray-800 dark:text-gray-300 uppercase tracking-wider">Total Volume</span>
                </div>
                <div class="text-3xl font-black text-gray-700 dark:text-white truncate">
                    ₦{{ number_format($totalCollected) }}
                </div>
                <div class="text-xs text-gray-500 mt-2 font-medium">
                    Total processed today
                </div>
            </div>
        </div>
    </div>

    <div class="w-full bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden mt-4">
        <div class="p-4 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
            <div class="font-bold text-lg text-gray-800 dark:text-white">
                🏆 Staff Performance
            </div>
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400 bg-white dark:bg-gray-700 px-3 py-1 rounded-full border border-gray-200 dark:border-gray-600">
                {{ $reportDate }}
            </div>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 uppercase text-xs">
                    <tr>
                        <th class="px-6 py-4 font-bold">Staff Name</th>
                        <th class="px-6 py-4 text-center font-bold">Transactions</th>
                        <th class="px-6 py-4 text-right font-bold">Amount Collected</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse($staffStats as $staff)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                        <td class="px-6 py-4 font-bold text-gray-900 dark:text-white flex items-center">
                            <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center text-blue-700 dark:text-blue-300 font-bold text-xs mr-3">
                                {{ substr($staff['name'], 0, 2) }}
                            </div>
                            {{ $staff['name'] }}
                        </td>
                        <td class="px-6 py-4 text-center text-gray-600 dark:text-gray-300">
                            <span class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded text-xs font-bold">
                                {{ $staff['count'] }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right font-mono font-bold text-green-600 dark:text-green-400">
                            ₦{{ number_format($staff['total']) }}
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="3" class="px-6 py-12 text-center text-gray-400 italic">
                            No transactions found for this date.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>