@php
    /** @var array $history */
    /** @var \Illuminate\Support\Collection|null $user */
@endphp

<div class="p-6 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
    <h2 class="text-2xl font-bold mb-4 text-gray-900 dark:text-white">My History — {{ $user->name }}</h2>

    @if(empty($history))
        <div class="text-gray-500 dark:text-gray-400">No history available for the selected period.</div>
    @endif

    @foreach($history as $date => $data)
        <div class="mb-4 border border-gray-200 dark:border-gray-700 rounded-lg p-4 bg-white dark:bg-gray-800">
            <div class="flex justify-between items-center">
                <div class="font-bold text-gray-800 dark:text-gray-100">{{ \Carbon\Carbon::parse($date)->format('l, d M Y') }}</div>
                <div class="text-sm text-gray-600 dark:text-gray-300">Orders: {{ $data['orders']->count() }} — ₦{{ number_format($data['orders_total']) }}</div>
            </div>

            <div class="mt-3 space-y-2">
                @foreach($data['orders'] as $order)
                    <div class="p-3 bg-gray-50 dark:bg-gray-700 rounded flex justify-between">
                        <div>
                            <div class="font-bold text-gray-800 dark:text-gray-100">Order #{{ $order->order_number }} — {{ ucfirst($order->destination ?? 'main') }}</div>
                            <div class="text-sm text-gray-600 dark:text-gray-300">
                                Table: {{ $order->table?->name }} — Status: {{ $order->status }}
                                @if($order->processed_by_user_id == $user->id && $order->user_id != $user->id)
                                    <span class="text-blue-600 dark:text-blue-400">Waiter: {{ $order->user->name ?? 'Unknown' }}</span>
                                @elseif($order->user_id == $user->id)
                                    <span class="text-green-600 dark:text-green-400">(Created by you)</span>
                                @endif
                            </div>
                            <div class="text-xs mt-2 text-gray-700 dark:text-gray-200">@foreach($order->items as $it) <span class="mr-2">{{ $it->quantity }}x {{ $it->product_name }}</span> @endforeach</div>
                        </div>
                        <div class="text-right">
                            <div class="font-bold text-gray-800 dark:text-gray-100">₦{{ number_format($order->total_amount) }}</div>
                            <div class="text-xs text-gray-600 dark:text-gray-300">Paid: ₦{{ number_format($order->amount_paid ?? 0) }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
