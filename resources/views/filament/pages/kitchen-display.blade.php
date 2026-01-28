<x-filament-panels::page>
    <div wire:poll.5s class="mb-6 flex justify-end">
        <a href="{{ \App\Filament\Pages\MyHistory::getUrl() }}" 
           class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
            <x-heroicon-o-clock class="w-4 h-4 mr-2"/>
            View Full History
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 w-full">
        <!-- Pending Orders -->
        <div class="lg:col-span-3 w-full">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Pending Orders</h3>
            <div wire:poll.5s="true" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 w-full">
        
        @forelse($orders as $order)
            @php
                // Filter items safely. change 'food' to 'drink' for the Bar Display
                $relevantItems = $order->items->filter(fn($item) => $item->product?->category?->type === 'food');
            @endphp
            {{-- 👇 Only show the Card if there are actual items to show --}}
            @if($relevantItems->isNotEmpty())
                <div class="bg-white dark:bg-gray-800 border-2 border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-md flex flex-col">
                
                <div class="bg-red-50 dark:bg-red-900/20 p-4 border-b border-red-100 dark:border-red-800 flex justify-between items-center">
                    <h3 class="font-bold text-xl text-gray-800 dark:text-white">
                        {{ $order->order_number }}
                    </h3>

                    <span class="text-xs font-mono text-gray-500 dark:text-gray-400">
                        {{ $order->created_at->diffForHumans() }}
                    </span>
                </div>

                <div class="p-4 flex-1 overflow-y-auto max-h-64 space-y-2">                   
                    {{-- 👇 NEW: Show Table Name --}}
                    <div class="text-indigo-600 dark:text-indigo-400 font-bold text-sm mt-1">
                        {{ $order->table->name ?? 'Takeaway' }}
                    </div>

                    {{-- 👇 NEW: Waiter Name --}}
                    <div class="text-xs text-gray-500 dark:text-gray-400 font-medium mt-1">
                        Waiter: {{ $order->user->name ?? 'Admin' }}
                    </div>
                    
                    @foreach($order->items as $item)
                        @if ($relevantItems->isNotEmpty() && $item->product?->category?->type === 'food')
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-lg text-gray-700 dark:text-gray-300">
                                {{ $item->quantity }}x
                            </span>
                            <span class="text-gray-600 dark:text-gray-400 flex-1 ml-2">
                                {{ $item->product_name }}
                            </span>
                        </div>
                        @endif
                    @endforeach
                </div>

                <div class="p-4 bg-gray-50 dark:bg-gray-900/50 border-t border-gray-200 dark:border-gray-700">
                    <button 
                        wire:click="markAsReady({{ $order->id }})"
                        class="w-full bg-blue-600 hover:bg-blue-700 dark:bg-blue-500 dark:hover:bg-blue-600 text-white font-bold py-3 rounded-lg shadow transition-all active:scale-95">
                        MARK READY
                    </button>
                </div>
                </div>
            @endif
        @empty
            <div class="col-span-full flex flex-col items-center justify-center p-10 text-gray-400 dark:text-gray-500">
                <x-heroicon-o-check-circle class="w-16 h-16 mb-4"/>
                <p class="text-xl">All caught up! No pending orders.</p>
            </div>
        @endforelse
        </div>
        </div>

        <!-- Sidebar with History and Stats -->
        <div class="lg:col-span-1 space-y-6 w-full">
            <!-- Recent History -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Recent History (Ready/Served - Last 7 Days)</h3>
                </div>
                <div class="p-4 max-h-64 overflow-y-auto">
                    @forelse($recentHistory as $order)
                        <div class="mb-3 pb-3 border-b border-gray-100 dark:border-gray-700 last:border-b-0">
                            <div class="flex justify-between items-start">
                                <div>
                                    <div class="font-medium text-gray-900 dark:text-white">{{ $order->order_number }}</div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $order->created_at->format('H:i') }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $order->table->name ?? 'Takeaway' }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-medium text-green-600 dark:text-green-400">{{ $order->status }}</div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-4 text-gray-500 dark:text-gray-400">
                            No recent orders
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- Items Sold Today -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Items Sold Today</h3>
                </div>
                <div class="p-4 max-h-64 overflow-y-auto">
                    @forelse($itemsSold as $item)
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ $item->name }}</span>
                            <span class="font-bold text-blue-600 dark:text-blue-400">{{ $item->total_sold }}</span>
                        </div>
                    @empty
                        <div class="text-center py-4 text-gray-500 dark:text-gray-400">
                            No items sold today
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>