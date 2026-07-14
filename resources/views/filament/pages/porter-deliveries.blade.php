<x-filament-panels::page>
    <div class="space-y-6">
        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <h3 class="font-bold text-gray-900 dark:text-white mb-3">Ready for pickup</h3>

            @if($readyForPickup->isEmpty())
                <p class="text-gray-400 text-sm">Nothing waiting for pickup.</p>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach($readyForPickup as $order)
                        <div class="p-4 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20">
                            <div class="font-bold text-gray-900 dark:text-white">Room {{ $order->booking->room->number }}</div>
                            <div class="text-xs text-gray-500 mb-2">{{ $order->booking->guest->name }} · #{{ $order->order_number }}</div>
                            <ul class="text-sm text-gray-700 dark:text-gray-300 mb-3">
                                @foreach($order->items as $item)
                                    <li>{{ $item->quantity }}x {{ $item->product_name }}</li>
                                @endforeach
                            </ul>
                            <button type="button" wire:click="pickUp({{ $order->id }})" class="w-full px-3 py-2 rounded-lg bg-amber-600 hover:bg-amber-700 text-white font-bold text-sm">
                                Pick Up
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
            <h3 class="font-bold text-gray-900 dark:text-white mb-3">Out for delivery</h3>

            @if($inTransit->isEmpty())
                <p class="text-gray-400 text-sm">Nothing currently out for delivery.</p>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach($inTransit as $order)
                        <div class="p-4 rounded-lg border border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20">
                            <div class="font-bold text-gray-900 dark:text-white">Room {{ $order->booking->room->number }}</div>
                            <div class="text-xs text-gray-500 mb-2">{{ $order->booking->guest->name }} · #{{ $order->order_number }}</div>
                            <div class="text-xs text-gray-500 mb-2">Carried by {{ $order->pickedUpBy?->name }}</div>
                            <ul class="text-sm text-gray-700 dark:text-gray-300 mb-3">
                                @foreach($order->items as $item)
                                    <li>{{ $item->quantity }}x {{ $item->product_name }}</li>
                                @endforeach
                            </ul>
                            <button type="button" wire:click="confirmDelivered({{ $order->id }})" class="w-full px-3 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm">
                                Confirm Delivered
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
