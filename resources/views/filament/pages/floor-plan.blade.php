<x-filament-panels::page>
    {{-- 👇 This makes the page refresh automatically every 5 seconds --}}
    <div wire:poll.5s>
        
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            @foreach($this->getViewData()['tables'] as $table)
                @php
                    $isOccupied = $table->status === 'occupied';
                    $isReserved = $table->status === 'reserved';
                    
                    // Get details if occupied
                    $activeOrder = $table->orders->first();
                    $total = $activeOrder ? $activeOrder->total_amount : 0;
                    $orderTime = $activeOrder ? $activeOrder->created_at->diffForHumans() : '';
                @endphp

                <div class="relative p-4 rounded-xl border shadow-sm transition hover:shadow-md
                    {{ $isOccupied ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800' : ($isReserved ? 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800' : 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800') }}">
                    
                    {{-- Header: Name & Icon --}}
                    <div class="flex justify-between items-start mb-2">
                        <h3 class="text-xl font-black {{ $isOccupied ? 'text-red-800 dark:text-red-300' : 'text-gray-700 dark:text-gray-300' }}">
                            {{ $table->name }}
                        </h3>
                        
                        {{-- Status Badge --}}
                        <span class="px-2 py-1 rounded text-xs font-bold uppercase tracking-wide
                            {{ $isOccupied ? 'bg-red-200 dark:bg-red-800 text-red-800 dark:text-red-200' : ($isReserved ? 'bg-yellow-200 dark:bg-yellow-800 text-yellow-800 dark:text-yellow-200' : 'bg-green-200 dark:bg-green-800 text-green-800 dark:text-green-200') }}">
                            {{ $table->status }}
                        </span>
                    </div>

                    {{-- Body: Details --}}
                    <div class="space-y-1 mb-4">
                        @if($isOccupied)
                            <div class="text-2xl font-bold text-gray-800 dark:text-gray-200">₦{{ number_format($total) }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Seated {{ $orderTime }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 font-medium">Order: #{{ $activeOrder->order_number }}</div>
                        @elseif($isReserved)
                            <div class="text-sm text-yellow-700 dark:text-yellow-400 italic">Reserved for Guest</div>
                        @else
                            <div class="text-sm text-green-700 dark:text-green-400 flex items-center gap-1">
                                <x-heroicon-m-check-circle class="w-4 h-4"/> Ready
                            </div>
                        @endif
                    </div>

                    {{-- Footer: Actions --}}
                    <div class="grid grid-cols-2 gap-2 mt-2">
                        {{-- 1. BUTTON: Go to POS (Opens in new tab with ID) --}}
                        <a href="/admin/pos-page?table_id={{ $table->id }}" 
                        class="col-span-2 text-center bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 py-2 rounded text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                            {{ $isOccupied ? '⚡ Manage Order' : '➕ New Order' }}
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
        
    </div>
</x-filament-panels::page>