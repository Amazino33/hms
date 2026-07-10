<div wire:init="load" wire:poll.30s="loadCurrentShift"
     x-data="{
         showModal: false,
         showDeclarationModal: false,
         declaredCash: 0,
         declaredPos: 0
     }"
     @open-shift-modal.window="showModal = true"
     @shift-started.window="showModal = false"
     @shift-ended.window="showModal = false; showDeclarationModal = false; declaredCash = 0; declaredPos = 0;">
@if(! $ready)
    {{-- Skeleton shown until load() fires and $ready becomes true --}}
    <div></div>
@else
<div x-show="showModal" x-cloak class="fixed inset-0 bg-black/50 z-[50] flex items-center justify-center p-4 backdrop-blur-sm">
    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden border border-gray-200 dark:border-gray-700 relative max-h-[90vh] overflow-y-auto">
        <!-- Loading Overlay -->
        <div wire:loading wire:target="startShift" class="absolute inset-0 bg-white/80 dark:bg-gray-900/80 flex items-center justify-center z-10 rounded-2xl">
            <div class="flex flex-col items-center space-y-3">
                <div class="w-8 h-8 border-4 border-blue-600 border-t-transparent rounded-full animate-spin"></div>
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Starting your shift...</p>
            </div>
        </div>
        <div class="bg-gray-50 dark:bg-gray-800 p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center sticky top-0">
            <h3 class="text-xl font-bold text-gray-900 dark:text-white">⏰ Shift Management</h3>
            <button @click="showModal = false"
                    wire:loading.attr="disabled" wire:target="startShift"
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
                        {{-- Bartenders/chefs don't handle cash — there's nothing
                             for them to declare here, and User::endShift()
                             throws for them regardless. This button used to be
                             pure Alpine (@click="showDeclarationModal = true"),
                             which is exactly why two earlier attempts to gate
                             this in PHP never actually took effect: nothing
                             here ever called into Livewire for bartender/chef
                             to begin with. --}}
                        @if($currentShift && in_array($currentShift->type, ['bartender', 'chef'], true))
                            <button wire:click="goToHandoverCount"
                                @if($isProcessing) disabled @endif
                                class="px-3 py-2 bg-red-600 hover:bg-red-700 disabled:bg-red-400 text-white text-xs rounded-lg font-medium transition-colors touch-manipulation flex items-center justify-center space-x-1">
                                <span>End Shift</span>
                            </button>
                        @else
                            <button @click="showDeclarationModal = true"
                                @if($isProcessing) disabled @endif
                                class="px-3 py-2 bg-red-600 hover:bg-red-700 disabled:bg-red-400 text-white text-xs rounded-lg font-medium transition-colors touch-manipulation flex items-center justify-center space-x-1">
                                <span>End Shift</span>
                            </button>
                        @endif
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
                    <button @click="$wire.call('startShift')"
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
    </div>
</div>
    @endif

    <!-- SHIFT DECLARATION MODAL -->
    <div x-show="showDeclarationModal" x-cloak class="fixed inset-0 bg-black/50 z-[50] flex items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden border border-gray-200 dark:border-gray-700 max-h-[90vh] overflow-y-auto relative">
            <!-- Loading Overlay -->
            <div wire:loading wire:target="confirmShiftEnd" class="absolute inset-0 bg-white/80 dark:bg-gray-900/80 flex items-center justify-center z-10 rounded-2xl">
                <div class="flex flex-col items-center space-y-3">
                    <div class="w-8 h-8 border-4 border-red-600 border-t-transparent rounded-full animate-spin"></div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Processing...</p>
                </div>
            </div>

            <!-- Header -->
            <div class="bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 p-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">End Shift Declaration</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Declare your cash and POS amounts</p>
            </div>

            <!-- Content -->
            <div class="p-6 space-y-6">
                <!-- Cash Amount -->
                <div>
                    <label for="declaredCash" class="block text-sm font-bold text-gray-900 dark:text-white mb-2">
                        💵 Declared Cash Amount
                    </label>
                    <div class="relative">
                        <span class="absolute left-3 top-3 text-gray-600 dark:text-gray-400 font-bold">₦</span>
                        <input 
                            type="number" 
                            id="declaredCash"
                            x-model.number="declaredCash"
                            placeholder="0.00"
                            min="0"
                            step="0.01"
                            wire:loading.attr="disabled"
                            wire:target="confirmShiftEnd"
                            class="w-full pl-8 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-800 dark:text-white focus:ring-2 focus:ring-red-500 focus:border-transparent disabled:opacity-50"
                        />
                    </div>
                </div>

                <!-- POS Amount -->
                <div>
                    <label for="declaredPos" class="block text-sm font-bold text-gray-900 dark:text-white mb-2">
                        💳 Declared POS Amount
                    </label>
                    <div class="relative">
                        <span class="absolute left-3 top-3 text-gray-600 dark:text-gray-400 font-bold">₦</span>
                        <input 
                            type="number" 
                            id="declaredPos"
                            x-model.number="declaredPos"
                            placeholder="0.00"
                            min="0"
                            step="0.01"
                            wire:loading.attr="disabled"
                            wire:target="confirmShiftEnd"
                            class="w-full pl-8 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-800 dark:text-white focus:ring-2 focus:ring-red-500 focus:border-transparent disabled:opacity-50"
                        />
                    </div>
                </div>

                <!-- Total Declaration -->
                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Total Declared:</span>
                        <span class="text-xl font-bold text-gray-900 dark:text-white">₦<span x-text="(parseFloat(declaredCash || 0) + parseFloat(declaredPos || 0)).toLocaleString('en', {minimumFractionDigits: 2})"></span></span>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex gap-3">
                    <button 
                        @click="showDeclarationModal = false; declaredCash = 0; declaredPos = 0;"
                        wire:loading.attr="disabled"
                        wire:target="confirmShiftEnd"
                        class="flex-1 px-4 py-2 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-900 dark:text-white rounded-lg font-medium transition-colors disabled:opacity-50"
                    >
                        Cancel
                    </button>
                    <button 
                        @click="$wire.call('confirmShiftEnd', declaredCash, declaredPos)"
                        wire:loading.attr="disabled"
                        @if($isProcessing) disabled @endif
                        class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 disabled:bg-red-400 text-white rounded-lg font-medium transition-colors flex items-center justify-center space-x-2"
                    >
                        <svg wire:loading wire:target="confirmShiftEnd" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                        <span wire:loading.remove wire:target="confirmShiftEnd">Confirm & End</span>
                        <span wire:loading wire:target="confirmShiftEnd">Processing...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>
