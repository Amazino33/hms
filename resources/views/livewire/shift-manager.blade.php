<div wire:poll.30s="loadCurrentShift">
@if($showModal)
<div class="fixed inset-0 bg-black/50 z-[50] flex items-center justify-center p-4 backdrop-blur-sm">
    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-gray-200 dark:border-gray-700 relative max-h-[90vh] overflow-y-auto">
        <!-- Loading Overlay -->
        <div wire:loading wire:target="startShift,endShift" class="absolute inset-0 bg-white/80 dark:bg-gray-900/80 flex items-center justify-center z-10 rounded-2xl">
            <div class="flex flex-col items-center space-y-3">
                <div class="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    <span wire:loading wire:target="startShift">Starting your shift...</span>
                    <span wire:loading wire:target="endShift">Ending your shift...</span>
                </p>
            </div>
        </div>
        <div class="bg-gray-50 dark:bg-gray-800 p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center sticky top-0">
            <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                <span wire:loading wire:target="startShift,endShift" class="opacity-50">⏰ Shift Management</span>
                <span wire:loading.remove wire:target="startShift,endShift">⏰ Shift Management</span>
            </h3>
            <button wire:click="closeModal" 
                    wire:loading.attr="disabled" wire:target="startShift,endShift"
                    class="text-gray-400 hover:text-red-500 touch-manipulation p-2">
                <span class="text-2xl">&times;</span>
            </button>
        </div>
        <div class="p-4 sm:p-6">
            <!-- Processing Message -->
            @if($isProcessing)
                <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-xl p-4 mb-4">
                    <div class="flex items-center space-x-3">
                        <div class="w-5 h-5 border-2 border-yellow-600 border-t-transparent rounded-full animate-spin"></div>
                        <div>
                            <div class="text-sm font-medium text-yellow-800 dark:text-yellow-300">
                                {{ $currentShift ? 'Stopping your shift...' : 'Starting your shift...' }}
                            </div>
                            <div class="text-xs text-yellow-600 dark:text-yellow-400">
                                Please wait while we process your request.
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Mobile-First Layout (default for all screens) -->
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
                <!-- Mobile Active Shift Card (enhanced on desktop) -->
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
                                <div class="text-xs text-green-600 dark:text-green-400">{{ $shiftDuration }} active</div>
                            </div>
                        </div>
                        <button wire:click="endShift"
                            wire:loading.attr="disabled"
                            @if($isProcessing) disabled @endif
                            onclick="return confirm(\"Are you sure you want to end your current shift? This action cannot be undone.\")"
                            class="px-3 py-2 bg-red-600 hover:bg-red-700 disabled:bg-red-400 text-white text-xs rounded-lg font-medium transition-colors touch-manipulation flex items-center justify-center space-x-1">
                            <svg wire:loading wire:target="endShift" class="w-3 h-3 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                            </svg>
                            <span wire:loading.remove wire:target="endShift">End Shift</span>
                            <span wire:loading wire:target="endShift">Stopping Shift...</span>
                        </button>
                    </div>

                    <div class="space-y-2 text-xs">
                        <div class="flex justify-between">
                            <span class="text-green-700 dark:text-green-400">Started:</span>
                            <span class="text-green-800 dark:text-green-300 font-medium">{{ $currentShift->started_at->format("g:i A") }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-green-700 dark:text-green-400">Date:</span>
                            <span class="text-green-800 dark:text-green-300 font-medium">{{ $currentShift->started_at->format("M j, Y") }}</span>
                        </div>
                    </div>

                    <!-- Desktop enhancements -->
                    <div class="hidden sm:block mt-4 pt-4 border-t border-green-200 dark:border-green-700">
                        <div class="text-sm text-green-700 dark:text-green-400 mb-2">
                            <strong>Total Payments:</strong> ₦{{ number_format($currentShift->payments->sum("amount")) }}
                        </div>
                        <div class="text-xs text-green-600 dark:text-green-500">
                            Duration: {{ $shiftDuration }}
                        </div>
                    </div>
                </div>

                <!-- Mobile Shift Stats (shown on mobile, hidden on desktop where it's in the card) -->
                <div class="grid grid-cols-2 gap-3 mb-4 sm:hidden">
                    <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-3 border border-blue-200 dark:border-blue-800">
                        <div class="flex items-center space-x-2">
                            <div class="w-6 h-6 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <svg class="w-3 h-3 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                                </svg>
                            </div>
                            <div>
                                <div class="text-xs text-blue-700 dark:text-blue-400">Payments</div>
                                <div class="text-sm font-bold text-blue-800 dark:text-blue-300">₦{{ number_format($currentShift->payments->sum("amount")) }}</div>
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
                <!-- Mobile Off Shift Card (enhanced on desktop) -->
                <div class="bg-gradient-to-r from-gray-50 to-slate-50 dark:from-gray-800 dark:to-slate-800 rounded-xl p-4 sm:p-6 border border-gray-200 dark:border-gray-700 text-center">
                    <div class="w-16 h-16 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <h4 class="text-lg sm:text-xl font-bold text-gray-900 dark:text-white mb-2">Ready to Start?</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4 sm:mb-6">Begin your shift to start processing orders</p>
                    <button wire:click="startShift"
                        wire:loading.attr="disabled"
                        @if($isProcessing) disabled @endif
                        class="w-full px-6 py-3 sm:px-8 sm:py-4 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white rounded-xl font-bold text-base transition-colors touch-manipulation active:scale-95 flex items-center justify-center space-x-2">
                        <svg wire:loading wire:target="startShift" class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                        <span wire:loading.remove wire:target="startShift">🚀 Start Shift</span>
                        <span wire:loading wire:target="startShift">Starting Shift...</span>
                    </button>
                </div>
            @endif
        </div>

    @endif

    <!-- Toast Notification Container -->
    <div x-data="{
        show: false,
        message: '',
        type: 'success',
        showToast(event) {
            this.message = event.detail[0].message;
            this.type = event.detail[0].type;
            this.show = true;
            setTimeout(() => { this.show = false; }, 3000);
        }
    }"
    @notify.window="showToast($event)"
    x-show="show"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="transform translate-x-full"
    x-transition:enter-end="transform translate-x-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="transform translate-x-0"
    x-transition:leave-end="transform translate-x-full"
    class="fixed top-20 right-6 z-[60] max-w-sm w-full"
    style="display: none;">
        <div :class="{
            'bg-green-500': type === 'success',
            'bg-red-500': type === 'error',
            'bg-yellow-500': type === 'warning',
            'bg-blue-500': type === 'info'
        }" class="px-6 py-4 rounded-xl shadow-2xl text-white font-medium">
            <div class="flex items-center space-x-3">
                <svg x-show="type === 'success'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <svg x-show="type === 'error'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <span x-text="message"></span>
            </div>
        </div>
    </div>
</div>
