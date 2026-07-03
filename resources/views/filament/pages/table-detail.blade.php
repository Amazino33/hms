<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Table Header --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-3 md:p-4 mx-3 md:mx-6 my-4 md:my-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-lg md:rounded-xl flex items-center justify-center shadow-md">
                        <svg class="w-5 h-5 md:w-6 md:h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-lg md:text-xl font-bold text-gray-900 dark:text-white">{{ $table->name }}</h1>
                        <p class="text-xs md:text-sm text-gray-500 dark:text-gray-400">Table Details & Order Management</p>
                    </div>
                </div>

                {{-- Status Badge --}}
                <div class="flex items-center gap-3">
                    <span class="px-3 py-1 md:px-4 md:py-2 rounded-full text-xs md:text-sm font-bold uppercase tracking-wide {{
                        $table->status === 'occupied' ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300' :
                        ($table->status === 'reserved' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300' :
                        ($table->status === 'cleaning' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300' :
                        ($table->status === 'maintenance' ? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' :
                        'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300')))
                    }}">
                        {{ $table->status }}
                    </span>
                </div>
            </div>
        </div>

        @if($orders && $orders->isNotEmpty())
            {{-- Per-order status + confirm-served --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <h3 class="text-base md:text-lg font-semibold text-gray-900 dark:text-white mb-4">Orders on this ticket</h3>
                <div class="space-y-3">
                    @foreach($orders as $ticketOrder)
                        <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                            <div>
                                <div class="font-medium text-gray-900 dark:text-white">
                                    #{{ $ticketOrder->order_number }} &middot; {{ ucfirst($ticketOrder->destination ?? 'main') }}
                                </div>
                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                    Status: <span class="font-semibold">{{ ucfirst($ticketOrder->status) }}</span>
                                    @if($ticketOrder->served_at)
                                        &middot; Served {{ $ticketOrder->served_at->diffForHumans() }}
                                    @endif
                                </div>
                            </div>

                            @if($ticketOrder->status === 'ready')
                                <button wire:click="confirmServed({{ $ticketOrder->id }})"
                                    class="inline-flex items-center gap-2 px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium transition text-sm">
                                    Confirm Served
                                </button>
                            @elseif($ticketOrder->status === 'served')
                                <span class="px-3 py-1 rounded-full text-xs font-bold uppercase bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300">
                                    Served
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if($order)
            {{-- Order Summary --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h2 class="text-xl md:text-2xl font-bold text-gray-900 dark:text-white">Current Order</h2>
                        <p class="text-gray-500 dark:text-gray-400">Order #{{ $order->order_number }}</p>
                    </div>

                    {{-- Order Status --}}
                    <div class="flex items-center gap-3">
                        @php
                            $statusConfig = [
                                'pending' => ['color' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200', 'icon' => ''],
                                'preparing' => ['color' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300', 'icon' => ''],
                                'ready' => ['color' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300', 'icon' => ''],
                                'served' => ['color' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300', 'icon' => ''],
                            ];
                            $statusInfo = $statusConfig[$order->status] ?? $statusConfig['pending'];
                        @endphp
                        <span class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium {{ $statusInfo['color'] }}">
                            {{ $statusInfo['icon'] }} {{ ucfirst($order->status) }}
                        </span>
                    </div>
                </div>

                {{-- Order Details --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Order Time</div>
                        <div class="text-base md:text-lg font-semibold text-gray-900 dark:text-white">{{ $order->created_at->format('M j, Y g:i A') }}</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">Seated {{ $order->created_at->diffForHumans() }}</div>
                    </div>

                    <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Total Items</div>
                        <div class="text-base md:text-lg font-semibold text-gray-900 dark:text-white">{{ $orderItems->sum('quantity') }}</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $orderItems->count() }} different items</div>
                    </div>

                    <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                        <div class="text-sm text-gray-500 dark:text-gray-400">Total Amount</div>
                        <div class="text-xl md:text-2xl font-bold text-indigo-600 dark:text-indigo-400">₦{{ number_format($orderItems->sum(fn($item) => $item->unit_price * $item->quantity)) }}</div>
                    </div>
                </div>

                {{-- Order Items --}}
                <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                    <h3 class="text-base md:text-lg font-semibold text-gray-900 dark:text-white mb-4">Order Items</h3>
                    <div class="space-y-3">
                        @foreach($orderItems as $item)
                            <div class="flex justify-between items-center p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                <div class="flex-1">
                                    <div class="font-medium text-gray-900 dark:text-white">{{ $item->product_name }}</div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">₦{{ number_format($item->unit_price) }} each</div>
                                </div>
                                <div class="text-right">
                                    <div class="font-semibold text-gray-900 dark:text-white">x{{ $item->quantity }}</div>
                                    <div class="text-sm font-medium text-indigo-600 dark:text-indigo-400">₦{{ number_format($item->unit_price * $item->quantity) }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @else
            {{-- No Active Order --}}
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <div class="text-center py-12">
                    <div class="w-16 h-16 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                    </div>
                    <h3 class="text-base md:text-lg font-medium text-gray-900 dark:text-white mb-2">No Active Order</h3>
                    <p class="text-gray-500 dark:text-gray-400">This table currently has no active orders.</p>
                </div>
            </div>
        @endif

        {{-- Action Buttons --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
            <div class="flex flex-wrap gap-4">
                <a href="/admin/floor-plan"
                   class="inline-flex items-center gap-2 px-6 py-3 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 rounded-lg font-medium transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    Back to Floor Plan
                </a>

                @if($order && $order->user_id === auth()->id())
                    <a href="/admin/pos-page?table_id={{ $table->id }}"
                       class="inline-flex items-center gap-2 px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                        </svg>
                        Manage Order (POS)
                    </a>

                    <button wire:click="cancelOrder"
                       class="inline-flex items-center gap-2 px-6 py-3 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition"
                       onclick="return confirm('Are you sure you want to cancel this order?')">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        Cancel Order
                    </button>
                @elseif($order)
                    {{-- Show order details but no management buttons --}}
                @else
                    <a href="/admin/pos-page?table_id={{ $table->id }}"
                       class="inline-flex items-center gap-2 px-6 py-3 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Start New Order
                    </a>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>

@push('scripts')
<script>
// No JavaScript functions needed - navigation only
</script>
@endpush