<x-filament-panels::page>
    <div class="space-y-6">

        <!-- Date Selector -->
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
            {{ $this->form }}
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <div class="flex items-center">
                    <div class="p-2 bg-blue-100 dark:bg-blue-900/20 rounded-lg">
                        <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Staff</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $totalStaff }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <div class="flex items-center">
                    <div class="p-2 bg-green-100 dark:bg-green-900/20 rounded-lg">
                        <svg class="w-6 h-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Completed Shifts</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $completedShifts }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <div class="flex items-center">
                    <div class="p-2 bg-yellow-100 dark:bg-yellow-900/20 rounded-lg">
                        <svg class="w-6 h-6 text-yellow-600 dark:text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active Shifts</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $activeShifts }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <div class="flex items-center">
                    <div class="p-2 bg-purple-100 dark:bg-purple-900/20 rounded-lg">
                        <svg class="w-6 h-6 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Payments</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">₦{{ number_format($totalPayments) }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Staff Shifts Details -->
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-xl font-bold text-gray-900 dark:text-white">Staff Shift Details - {{ $reportDate }}</h2>
            </div>

            <div class="p-6">
                @forelse($staffShifts as $staffData)
                    <div class="mb-6 last:mb-0">
                        <div class="flex justify-between items-center mb-4 p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <div>
                                <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ $staffData['user']->name }}</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ $staffData['total_shifts'] }} shifts • {{ $staffData['total_duration'] }} minutes total • 
                                    ₦{{ number_format($staffData['total_payments']) }} collected • {{ $staffData['total_transactions'] }} transactions
                                </p>
                            </div>
                        </div>

                        <div class="space-y-3 ml-4">
                            @foreach($staffData['shifts'] as $shift)
                                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 {{ $shift['is_active'] ? 'bg-green-50 dark:bg-green-900/10 border-green-200 dark:border-green-800' : 'bg-white dark:bg-gray-800' }}">
                                    <div class="flex justify-between items-start mb-3">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2 mb-1">
                                                <span class="font-bold text-gray-900 dark:text-white">Shift #{{ $shift['id'] }}</span>
                                                @if($shift['is_active'])
                                                    <span class="px-2 py-1 bg-green-100 text-green-800 text-xs font-medium rounded-full">Active</span>
                                                @else
                                                    <span class="px-2 py-1 bg-gray-100 text-gray-800 text-xs font-medium rounded-full">Completed</span>
                                                @endif
                                            </div>
                                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                                Started: {{ $shift['started_at']->format('M j, Y g:i A') }}
                                                @if($shift['ended_at'])
                                                    • Ended: {{ $shift['ended_at']->format('g:i A') }}
                                                    • Duration: {{ $shift['duration'] }} minutes
                                                @else
                                                    • Duration: {{ $shift['started_at']->diffForHumans(now(), true) }}
                                                @endif
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-lg font-bold text-green-600 dark:text-green-400">
                                                ₦{{ number_format($shift['total_payments']) }}
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ $shift['transaction_count'] }} transactions
                                            </div>
                                        </div>
                                    </div>

                                    @if($shift['payments']->count() > 0)
                                        <div class="border-t border-gray-200 dark:border-gray-700 pt-3">
                                            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Payment Breakdown</h4>
                                            <div class="grid grid-cols-2 gap-4 text-sm">
                                                <div>
                                                    <span class="text-gray-600 dark:text-gray-400">Cash:</span>
                                                    <span class="font-mono font-bold text-green-600">₦{{ number_format($shift['cash_payments']) }}</span>
                                                </div>
                                                <div>
                                                    <span class="text-gray-600 dark:text-gray-400">POS/Transfer:</span>
                                                    <span class="font-mono font-bold text-blue-600">₦{{ number_format($shift['pos_payments']) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                        No shifts found for {{ $reportDate }}
                    </div>
                @endforelse
            </div>
        </div>

    </div>
</x-filament-panels::page>