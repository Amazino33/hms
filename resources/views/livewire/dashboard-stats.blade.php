<?php

use Livewire\Component;
use App\Models\Order;
use App\Models\Table;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

new class extends Component {
    public bool $ready = false;

    public function load(): void
    {
        $this->ready = true;
    }

    public function with()
    {
        if (! $this->ready) {
            return [
                'todaySales' => null,
                'ordersToday' => null,
                'activeTables' => null,
                'avgOrderTime' => null,
                'recentActivity' => collect(),
            ];
        }

        $todayKey = Carbon::today()->toDateString();

        $todaySales = Cache::remember("dashboard:today_sales:{$todayKey}", 30, fn () =>
            Order::whereDate('created_at', Carbon::today())->where('status', '!=', 'cancelled')->sum('total_amount')
        );

        $ordersToday = Cache::remember("dashboard:orders_today:{$todayKey}", 30, fn () =>
            Order::whereDate('created_at', Carbon::today())->count()
        );

        $activeTables = Cache::remember('dashboard:active_tables', 30, fn () =>
            Table::where('status', 'occupied')->count()
        );

        $avgOrderTime = Cache::remember("dashboard:avg_order_time:{$todayKey}", 60, function () {
            $served = Order::whereDate('created_at', Carbon::today())->where('status', 'served')->get();
            if ($served->isEmpty()) return null;
            return round($served->map(fn($o) => $o->updated_at->diffInMinutes($o->created_at))->average());
        });

        $recentActivity = Cache::remember('dashboard:recent_activity', 30, fn () =>
            Order::with('user')->latest()->limit(6)->get()
        );

        return compact('todaySales', 'ordersToday', 'activeTables', 'avgOrderTime', 'recentActivity');
    }
};
?>

<div wire:init="load">
    <!-- Stats Cards -->
    <div class="p-4 space-y-4">
        <div class="grid grid-cols-2 gap-3">
            @if(is_null($todaySales))
                @for($i=0;$i<2;$i++)
                    <div class="animate-pulse bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-gray-200 dark:bg-gray-700 rounded-lg"></div>
                            <div class="space-y-2 flex-1">
                                <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded-full w-2/3"></div>
                                <div class="h-5 bg-gray-300 dark:bg-gray-600 rounded-full w-1/2"></div>
                            </div>
                        </div>
                    </div>
                @endfor
            @else
                <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path></svg>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Today's Sales</div>
                            <div class="text-lg font-bold text-gray-900 dark:text-white">₦{{ number_format($todaySales) }}</div>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Orders Today</div>
                            <div class="text-lg font-bold text-gray-900 dark:text-white">{{ $ordersToday }}</div>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="grid grid-cols-2 gap-3">
            @if(is_null($todaySales))
                @for($i=0;$i<2;$i++)
                    <div class="animate-pulse bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center space-x-3">
                            <div class="w-10 h-10 bg-gray-200 dark:bg-gray-700 rounded-lg"></div>
                            <div class="space-y-2 flex-1">
                                <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded-full w-2/3"></div>
                                <div class="h-5 bg-gray-300 dark:bg-gray-600 rounded-full w-1/2"></div>
                            </div>
                        </div>
                    </div>
                @endfor
            @else
                <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Active Tables</div>
                            <div class="text-lg font-bold text-gray-900 dark:text-white">{{ $activeTables }}</div>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                            <svg class="w-5 h-5 text-orange-600 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Avg. Order Time</div>
                            <div class="text-lg font-bold text-gray-900 dark:text-white">{{ is_null($avgOrderTime) ? '--' : $avgOrderTime . ' min' }}</div>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <!-- Quick Actions (kept small) -->
    <div class="px-4 pb-4">
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-3">Quick Actions</h3>
            <div class="grid grid-cols-2 gap-3">
                <a href="{{ route('pos.index') }}" data-prefetch wire:navigate class="flex flex-col items-center p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800 hover:bg-blue-100 dark:hover:bg-blue-900/30 transition-colors touch-manipulation">
                    <svg class="w-6 h-6 text-blue-600 dark:text-blue-400 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path></svg>
                    <span class="text-xs font-medium text-blue-700 dark:text-blue-300">New Order</span>
                </a>

                <a href="#" wire:navigate class="flex flex-col items-center p-3 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-200 dark:border-green-800 hover:bg-green-100 dark:hover:bg-green-900/30 transition-colors touch-manipulation">
                    <svg class="w-6 h-6 text-green-600 dark:text-green-400 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                    <span class="text-xs font-medium text-green-700 dark:text-green-300">Reports</span>
                </a>

                <a href="{{ route('profile.edit') }}" data-prefetch wire:navigate class="flex flex-col items-center p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg border border-purple-200 dark:border-purple-800 hover:bg-purple-100 dark:hover:bg-purple-900/30 transition-colors touch-manipulation">
                    <svg class="w-6 h-6 text-purple-600 dark:text-purple-400 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <span class="text-xs font-medium text-purple-700 dark:text-purple-300">Settings</span>
                </a>

                <a href="#" wire:navigate class="flex flex-col items-center p-3 bg-orange-50 dark:bg-orange-900/20 rounded-lg border border-orange-200 dark:border-orange-800 hover:bg-orange-100 dark:hover:bg-orange-900/30 transition-colors touch-manipulation">
                    <svg class="w-6 h-6 text-orange-600 dark:text-orange-400 mb-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg>
                    <span class="text-xs font-medium text-orange-700 dark:text-orange-300">Support</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="px-4 pb-6">
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4">
            <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-3">Recent Activity</h3>

            @if(! $ready && $recentActivity->isEmpty())
                <div class="space-y-2 animate-pulse">
                    @for ($i = 0; $i < 4; $i++)
                    <div class="flex items-center space-x-3 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                        <div class="w-8 h-8 bg-gray-200 dark:bg-gray-600 rounded-full"></div>
                        <div class="flex-1 space-y-1">
                            <div class="h-3 bg-gray-200 dark:bg-gray-600 rounded-full w-3/4"></div>
                            <div class="h-2 bg-gray-200 dark:bg-gray-600 rounded-full w-1/2"></div>
                        </div>
                        <div class="h-2 bg-gray-200 dark:bg-gray-600 rounded-full w-10"></div>
                    </div>
                    @endfor
                </div>
            @elseif($recentActivity->isEmpty())
                <div class="space-y-3">
                    <div class="text-center py-4">
                        <div class="text-xs text-gray-500 dark:text-gray-400">No recent orders yet</div>
                        <div class="text-xs text-gray-400 dark:text-gray-500 mt-1">Start by creating your first order</div>
                    </div>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($recentActivity as $activity)
                        <div class="flex items-center space-x-3 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                            <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                                <svg class="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                            </div>
                            <div class="flex-1">
                                <div class="text-sm font-medium text-gray-900 dark:text-white">Order #{{ $activity->id }} — {{ ucfirst($activity->status) }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $activity->user?->name ?? 'System' }} — ₦{{ number_format($activity->total_amount) }}</div>
                            </div>
                            <div class="text-xs text-gray-400">{{ $activity->created_at->diffForHumans() }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
