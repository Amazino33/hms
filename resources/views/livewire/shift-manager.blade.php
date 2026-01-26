<div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4 lg:p-6" wire:poll.30s="loadCurrentShift">
    <!-- Desktop Layout (Hidden on Mobile) -->
    <div class="hidden lg:block">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-4 gap-2">
            <h3 class="text-base lg:text-lg font-bold text-gray-900 dark:text-white">Shift Management</h3>
            @if($currentShift)
                <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm font-medium self-start sm:self-auto">
                    On Shift
                </span>
            @else
                <span class="px-3 py-1 bg-gray-100 text-gray-800 rounded-full text-sm font-medium self-start sm:self-auto">
                    Off Shift
                </span>
            @endif
        </div>

        @if($currentShift)
            <div class="mb-4 p-4 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-800">
                <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
                    <div class="flex-1">
                        <p class="text-sm text-green-700 dark:text-green-400 font-medium">Current Shift</p>
                        <p class="text-xs text-green-600 dark:text-green-500">
                            Started: {{ $currentShift->started_at->format('M j, Y g:i A') }}
                        </p>
                        <p class="text-xs text-green-600 dark:text-green-500">
                            Duration: {{ $shiftDuration }} minutes
                        </p>
                    </div>
                    <button wire:click="endShift"
                        onclick="return confirm('Are you sure you want to end your current shift? This action cannot be undone.')"
                        class="w-full lg:w-auto px-4 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition-colors touch-manipulation">
                        End Shift
                    </button>
                </div>
            </div>
        @else
            <div class="text-center">
                <p class="text-gray-600 dark:text-gray-400 mb-4">You are currently off shift</p>
                <button wire:click="startShift"
                    class="w-full lg:w-auto px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors touch-manipulation">
                    Start Shift
                </button>
            </div>
        @endif

        @if($currentShift)
            <div class="mt-4 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                <p class="text-sm text-blue-700 dark:text-blue-400">
                    <strong>Total Payments:</strong> ₦{{ number_format($currentShift->payments->sum('amount')) }}
                </p>
            </div>
        @endif
    </div>

    <!-- Mobile Layout (Hidden on Desktop) -->
    <div class="lg:hidden">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">⏰ Shift</h3>
            @if($currentShift)
                <div class="flex items-center space-x-2">
                    <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs font-medium">
                        Active
                    </span>
                    <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                </div>
            @else
                <span class="px-2 py-1 bg-gray-100 text-gray-800 rounded-full text-xs font-medium">
                    Off Duty
                </span>
            @endif
        </div>

        @if($currentShift)
            <!-- Mobile Active Shift Card -->
            <div class="bg-gradient-to-r from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 rounded-xl p-4 border border-green-200 dark:border-green-800 mb-4">
                <div class="flex items-start justify-between mb-3">
                    <div class="flex items-center space-x-2">
                        <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                            <svg class="w-4 h-4 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div>
                            <div class="text-sm font-bold text-green-800 dark:text-green-300">On Shift</div>
                            <div class="text-xs text-green-600 dark:text-green-400">{{ $shiftDuration }}m active</div>
                        </div>
                    </div>
                    <button wire:click="endShift"
                        onclick="return confirm('Are you sure you want to end your current shift? This action cannot be undone.')"
                        class="px-3 py-2 bg-red-600 hover:bg-red-700 text-white text-xs rounded-lg font-medium transition-colors touch-manipulation">
                        End Shift
                    </button>
                </div>

                <div class="space-y-2 text-xs">
                    <div class="flex justify-between">
                        <span class="text-green-700 dark:text-green-400">Started:</span>
                        <span class="text-green-800 dark:text-green-300 font-medium">{{ $currentShift->started_at->format('g:i A') }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-green-700 dark:text-green-400">Date:</span>
                        <span class="text-green-800 dark:text-green-300 font-medium">{{ $currentShift->started_at->format('M j, Y') }}</span>
                    </div>
                </div>
            </div>

            <!-- Mobile Shift Stats -->
            <div class="grid grid-cols-2 gap-3 mb-4">
                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-3 border border-blue-200 dark:border-blue-800">
                    <div class="flex items-center space-x-2">
                        <div class="w-6 h-6 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                            <svg class="w-3 h-3 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                            </svg>
                        </div>
                        <div>
                            <div class="text-xs text-blue-700 dark:text-blue-400">Payments</div>
                            <div class="text-sm font-bold text-blue-800 dark:text-blue-300">₦{{ number_format($currentShift->payments->sum('amount')) }}</div>
                        </div>
                    </div>
                </div>

                <div class="bg-purple-50 dark:bg-purple-900/20 rounded-lg p-3 border border-purple-200 dark:border-purple-800">
                    <div class="flex items-center space-x-2">
                        <div class="w-6 h-6 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                            <svg class="w-3 h-3 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div>
                            <div class="text-xs text-purple-700 dark:text-purple-400">Orders</div>
                            <div class="text-sm font-bold text-purple-800 dark:text-purple-300">{{ $currentShift->payments->count() }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <!-- Mobile Off Shift Card -->
            <div class="bg-gradient-to-r from-gray-50 to-slate-50 dark:from-gray-800 dark:to-slate-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 text-center">
                <div class="w-16 h-16 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <h4 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Ready to Start?</h4>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">Begin your shift to start processing orders</p>
                <button wire:click="startShift"
                    class="w-full px-6 py-4 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-base transition-colors touch-manipulation active:scale-95">
                    🚀 Start Shift
                </button>
            </div>
        @endif
    </div>
</div>