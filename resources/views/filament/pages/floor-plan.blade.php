<x-filament-panels::page>
    <div wire:poll.1s>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            @foreach($this->getViewData()['tables'] as $table)
                @php
                    $isOccupied = $table->status === 'occupied';
                    $isReserved = $table->status === 'reserved';
                    $isCleaning = $table->status === 'cleaning';
                    $isMaintenance = $table->status === 'maintenance';
                    $isAvailable = $table->status === 'available';

                    // Get order details
                    $activeOrder = $table->orders->first();
                    $total = $activeOrder ? $activeOrder->total_amount : 0;
                    $orderTime = $activeOrder ? $activeOrder->created_at->diffForHumans() : '';
                    $orderStatus = $activeOrder ? $activeOrder->status : null;
                @endphp

                <div class="relative p-4 rounded-xl border shadow-sm transition hover:shadow-md
                    {{ $isOccupied ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 cursor-pointer' :
                       ($isReserved ? 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800' :
                       ($isCleaning ? 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800' :
                       ($isMaintenance ? 'bg-gray-50 dark:bg-gray-900/20 border-gray-200 dark:border-gray-800' :
                       'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800')))}}"
                    @if($isOccupied && $activeOrder) onclick="window.location.href='/admin/table-detail?table_id={{ $table->id }}'" @endif>

                    {{-- Header: Name & Icon --}}
                    <div class="flex justify-between items-start mb-2">
                        <h3 class="text-xl font-black {{
                            $isOccupied ? 'text-red-800 dark:text-red-300' :
                            ($isReserved ? 'text-yellow-800 dark:text-yellow-300' :
                            ($isCleaning ? 'text-blue-800 dark:text-blue-300' :
                            ($isMaintenance ? 'text-gray-800 dark:text-gray-300' :
                            'text-green-800 dark:text-green-300')))}}">
                            {{ $table->name }}
                        </h3>

                        {{-- Status Badge --}}
                        <span class="px-2 py-1 rounded text-xs font-bold uppercase tracking-wide {{
                            $isOccupied ? 'bg-red-200 dark:bg-red-800 text-red-800 dark:text-red-200' :
                            ($isReserved ? 'bg-yellow-200 dark:bg-yellow-800 text-yellow-800 dark:text-yellow-200' :
                            ($isCleaning ? 'bg-blue-200 dark:bg-blue-800 text-blue-800 dark:text-blue-200' :
                            ($isMaintenance ? 'bg-gray-200 dark:bg-gray-800 text-gray-800 dark:text-gray-200' :
                            'bg-green-200 dark:bg-green-800 text-green-800 dark:text-green-200')))}}">
                            {{ $table->status }}
                        </span>
                    </div>

                    {{-- Body: Details --}}
                    <div class="space-y-1 mb-4">
                        @if($isOccupied && $activeOrder)
                            <div class="text-2xl font-bold text-gray-800 dark:text-gray-200">₦{{ number_format($total) }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Seated {{ $orderTime }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 font-medium">Order: #{{ $activeOrder->order_number }}</div>

                            {{-- Order Status Indicator --}}
                            <div class="mt-2">
                                @php
                                    $statusConfig = [
                                        'pending' => ['color' => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200', 'icon' => '📝', 'label' => 'Pending'],
                                        'preparing' => ['color' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300', 'icon' => '👨‍🍳', 'label' => 'Preparing'],
                                        'ready' => ['color' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300', 'icon' => '✅', 'label' => 'Ready'],
                                        'served' => ['color' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300', 'icon' => '🍽️', 'label' => 'Served'],
                                    ];
                                    $statusInfo = $statusConfig[$orderStatus] ?? $statusConfig['pending'];
                                @endphp
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium {{ $statusInfo['color'] }}">
                                    {{ $statusInfo['icon'] }} {{ $statusInfo['label'] }}
                                </span>
                            </div>
                        @elseif($isReserved)
                            <div class="text-sm text-yellow-700 dark:text-yellow-400 italic">Reserved for Guest</div>
                        @elseif($isCleaning)
                            <div class="text-sm text-blue-700 dark:text-blue-400 flex items-center gap-1">
                                <x-heroicon-m-sparkles class="w-4 h-4"/> Being Cleaned
                            </div>
                        @elseif($isMaintenance)
                            <div class="text-sm text-gray-700 dark:text-gray-400 flex items-center gap-1">
                                <x-heroicon-m-wrench-screwdriver class="w-4 h-4"/> Under Maintenance
                            </div>
                        @else
                            <div class="text-sm text-green-700 dark:text-green-400 flex items-center gap-1">
                                <x-heroicon-m-check-circle class="w-4 h-4"/> Ready
                            </div>
                        @endif
                    </div>

                    {{-- Footer: Actions --}}
                    <div class="grid grid-cols-2 gap-2 mt-2">
                        {{-- 1. BUTTON: Go to POS (Opens in new tab with ID) --}}
                        @if($isAvailable)
                            <a href="/admin/pos-page?table_id={{ $table->id }}"
                            class="col-span-2 text-center bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 py-3 px-4 rounded-lg text-sm font-bold hover:bg-gray-50 dark:hover:bg-gray-700 transition shadow-sm">
                                ➕ New Order
                            </a>
                        @elseif($isOccupied && $activeOrder)
                            <a href="/admin/table-detail?table_id={{ $table->id }}"
                            class="col-span-2 text-center bg-indigo-600 hover:bg-indigo-700 text-white py-3 px-4 rounded-lg text-sm font-bold transition shadow-sm">
                                ⚡ Manage
                            </a>
                        @else
                            <button disabled
                            class="col-span-2 text-center bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 py-2 rounded text-sm font-bold cursor-not-allowed">
                                {{ $isCleaning ? '🧽 Cleaning' : ($isReserved ? '📅 Reserved' : '🔧 Maintenance') }}
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        @push('scripts')
        <script>
            // No JavaScript needed - using direct navigation to detail pages
        </script>
        @endpush
    </div>
</x-filament-panels::page>