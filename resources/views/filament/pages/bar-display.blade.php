<x-filament-panels::page>
    <div class="mb-6 flex justify-end">
        <a href="{{ \App\Filament\Pages\MyHistory::getUrl() }}" 
           class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-lg shadow-sm transition-all active:scale-95">
            <x-heroicon-o-clock class="w-5 h-5 mr-2"/>
            View Full History
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 w-full">
        <div class="lg:col-span-3 w-full">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                    <x-heroicon-o-fire class="w-6 h-6 text-amber-500" />
                    Pending Orders
                </h3>
            </div>
            
            <div wire:poll.5s class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5 w-full">
                @forelse($orders as $order)
                    @php
                        // Filter items safely.
                        $relevantItems = $order->items->filter(fn($item) => $item->product?->category?->type === 'drink');
                    @endphp
                    
                    @if($relevantItems->isNotEmpty() || $order->is_return)
                        
                        {{-- ========================================== --}}
                        {{-- 🛑 RETURN TICKET STATE                     --}}
                        {{-- ========================================== --}}
                        @if($order->is_return)
                            <div class="bg-white dark:bg-gray-800 border-2 border-red-500 rounded-xl overflow-hidden shadow-lg shadow-red-500/10 flex flex-col relative">
                                <div class="absolute top-0 left-0 w-full h-1 bg-red-500 animate-pulse"></div>

                                <div class="bg-red-50 dark:bg-red-900/30 p-4 border-b border-red-200 dark:border-red-800 flex justify-between items-center">
                                    <h3 class="font-black text-lg text-red-700 dark:text-red-400 flex items-center gap-2">
                                        <x-heroicon-o-arrow-uturn-left class="w-5 h-5" />
                                        {{ $order->order_number }}
                                    </h3>
                                    <span class="text-xs font-bold text-red-600/70 dark:text-red-400/70 bg-red-100 dark:bg-red-900/50 px-2 py-1 rounded-md">
                                        {{ $order->created_at->diffForHumans(null, true, true) }}
                                    </span>
                                </div>

                                <div class="p-4 flex-1 overflow-y-auto max-h-64 space-y-3">                    
                                    <div class="flex justify-between items-start border-b border-gray-100 dark:border-gray-700 pb-2">
                                        <div>
                                            <div class="text-gray-900 dark:text-white font-black text-sm uppercase tracking-wide">
                                                {{ $order->table->name ?? 'Takeaway' }}
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400 font-medium mt-0.5 flex items-center gap-1">
                                                <x-heroicon-o-user class="w-3 h-3"/>
                                                {{ $order->user->name ?? 'Admin' }}
                                            </div>
                                        </div>
                                    </div>
                                    
                                    @foreach($order->items as $item)
                                        <div class="bg-red-50/50 dark:bg-red-900/10 border border-red-100 dark:border-red-800/30 p-3 rounded-lg">
                                            <div class="flex items-start justify-between">
                                                <span class="font-black text-lg text-red-700 dark:text-red-400">{{ $item->quantity }}x</span>
                                                <span class="text-gray-800 dark:text-gray-200 font-bold flex-1 ml-3 leading-tight">
                                                    {{ $item->product_name }}
                                                </span>
                                            </div>
                                            <div class="mt-2 text-xs text-red-600 dark:text-red-400 bg-white dark:bg-gray-800 p-2 rounded border border-red-100 dark:border-red-900 flex gap-2 items-start">
                                                <x-heroicon-s-information-circle class="w-4 h-4 shrink-0"/>
                                                <span class="italic font-medium">{{ $item->return_reason ?? 'No reason provided' }}</span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="p-3 bg-gray-50 dark:bg-gray-900/50 border-t border-gray-200 dark:border-gray-700 mt-auto">
                                    <button 
                                        wire:click="confirmAndRestock({{ $order->id }})"
                                        wire:loading.attr="disabled"
                                        class="w-full bg-red-600 hover:bg-red-700 text-white font-black text-sm py-3 px-4 rounded-lg shadow-md transition-all active:scale-95 flex justify-center items-center gap-2 disabled:opacity-70 disabled:cursor-not-allowed">
                                        <x-heroicon-o-check-circle class="w-5 h-5" wire:loading.remove wire:target="confirmAndRestock({{ $order->id }})"/>
                                        <x-heroicon-o-arrow-path class="w-5 h-5 animate-spin" wire:loading wire:target="confirmAndRestock({{ $order->id }})"/>
                                        <span>CONFIRM & RESTOCK</span>
                                    </button>
                                </div>
                            </div>

                        {{-- ========================================== --}}
                        {{-- 🟢 STANDARD ORDER TICKET STATE             --}}
                        {{-- ========================================== --}}
                        @else
                            <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm hover:shadow-md transition-shadow flex flex-col">
                                <div class="bg-blue-50 dark:bg-blue-900/20 p-4 border-b border-blue-100 dark:border-blue-900/50 flex justify-between items-center">
                                    <h3 class="font-black text-lg text-gray-800 dark:text-gray-100">
                                        {{ $order->order_number }}
                                    </h3>
                                    <span class="text-xs font-bold text-blue-600 dark:text-blue-400 bg-blue-100 dark:bg-blue-900/50 px-2 py-1 rounded-md">
                                        {{ $order->created_at->diffForHumans(null, true, true) }}
                                    </span>
                                </div>

                                <div class="p-4 flex-1 overflow-y-auto max-h-64 space-y-3">                    
                                    <div class="flex justify-between items-start border-b border-gray-100 dark:border-gray-700 pb-2">
                                        <div>
                                            <div class="text-indigo-600 dark:text-indigo-400 font-black text-sm uppercase tracking-wide">
                                                {{ $order->table->name ?? 'Takeaway' }}
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400 font-medium mt-0.5 flex items-center gap-1">
                                                <x-heroicon-o-user class="w-3 h-3"/>
                                                {{ $order->user->name ?? 'Admin' }}
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="space-y-2 pt-1">
                                        @foreach($order->items as $item)
                                            @if ($item->product?->category?->type === 'drink')
                                            <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-700/30 p-2 rounded-lg border border-gray-100 dark:border-gray-700">
                                                <span class="font-black text-lg text-gray-900 dark:text-gray-100 w-10">
                                                    {{ $item->quantity }}x
                                                </span>
                                                <span class="text-gray-700 dark:text-gray-300 font-bold flex-1 leading-tight">
                                                    {{ $item->product_name }}
                                                </span>
                                            </div>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>

                                <div class="p-3 bg-gray-50 dark:bg-gray-900/50 border-t border-gray-200 dark:border-gray-700 mt-auto">
                                    <button 
                                        wire:click="markAsReady({{ $order->id }})"
                                        wire:loading.attr="disabled"
                                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-black text-sm py-3 px-4 rounded-lg shadow-sm transition-all active:scale-95 flex justify-center items-center gap-2 disabled:opacity-70 disabled:cursor-not-allowed">
                                        <x-heroicon-o-check class="w-5 h-5" wire:loading.remove wire:target="markAsReady({{ $order->id }})"/>
                                        <x-heroicon-o-arrow-path class="w-5 h-5 animate-spin" wire:loading wire:target="markAsReady({{ $order->id }})"/>
                                        <span>MARK READY</span>
                                    </button>
                                </div>
                            </div>
                        @endif

                    @endif
                @empty
                    <div class="col-span-full flex flex-col items-center justify-center p-12 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 text-gray-400 dark:text-gray-500 shadow-sm">
                        <div class="bg-gray-50 dark:bg-gray-900 p-4 rounded-full mb-4">
                            <x-heroicon-o-check-badge class="w-12 h-12 text-gray-300 dark:text-gray-600"/>
                        </div>
                        <p class="text-xl font-bold text-gray-500 dark:text-gray-400">All caught up!</p>
                        <p class="text-sm mt-1">No pending orders at the moment.</p>
                    </div>
                @endforelse
            </div>
        </div>

        <div class="lg:col-span-1 space-y-6 w-full">
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50 flex items-center gap-2">
                    <x-heroicon-o-clipboard-document-list class="w-5 h-5 text-gray-500"/>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white uppercase tracking-wide">Recent History</h3>
                </div>
                <div class="p-4 max-h-[400px] overflow-y-auto space-y-3">
                    @forelse($recentHistory as $order)
                        <div class="p-3 bg-gray-50 dark:bg-gray-700/30 rounded-lg border border-gray-100 dark:border-gray-700">
                            <div class="flex justify-between items-start mb-1">
                                <div class="font-bold text-sm text-gray-900 dark:text-white">{{ $order->order_number }}</div>
                                <span class="px-2 py-0.5 text-[10px] font-bold rounded-full uppercase tracking-wider 
                                    {{ $order->status === 'paid' ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-400' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-400' }}">
                                    {{ $order->status }}
                                </span>
                            </div>
                            <div class="flex justify-between items-center text-xs text-gray-500 dark:text-gray-400 font-medium">
                                <span class="flex items-center gap-1">
                                    <x-heroicon-o-map-pin class="w-3 h-3"/>
                                    {{ $order->table->name ?? 'Takeaway' }}
                                </span>
                                <span>{{ $order->created_at->format('H:i') }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-6 text-gray-400 dark:text-gray-500 text-sm font-medium">
                            No recent orders
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50 flex items-center gap-2">
                    <x-heroicon-o-chart-bar class="w-5 h-5 text-gray-500"/>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-white uppercase tracking-wide">Items Sold Today</h3>
                </div>
                <div class="p-0 max-h-[400px] overflow-y-auto">
                    @forelse($itemsSold as $item)
                        <div class="flex justify-between items-center p-3 border-b border-gray-100 dark:border-gray-700 last:border-b-0 hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $item->name }}</span>
                            <span class="font-black text-sm bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded text-gray-700 dark:text-gray-300">
                                {{ $item->total_sold }}
                            </span>
                        </div>
                    @empty
                        <div class="text-center py-8 text-gray-400 dark:text-gray-500 text-sm font-medium">
                            No items sold today
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>