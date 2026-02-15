<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class DashboardCache
{
    public static function clearForOrder(Order $order): void
    {
        $todayKey = Carbon::today()->toDateString();

        // Keys used by dashboard widgets / components
        $keys = [
            'sales_chart:7d',
            "stat:restaurant_revenue:{$todayKey}",
            "stat:waiter:ready:{$order->user_id}",
            "stat:waiter:sales:{$order->user_id}:{$todayKey}",
            "dashboard:today_sales:{$todayKey}",
            "dashboard:orders_today:{$todayKey}",
            'dashboard:active_tables',
            "dashboard:avg_order_time:{$todayKey}",
            'dashboard:recent_activity',
        ];

        foreach ($keys as $k) {
            Cache::forget($k);
        }
    }

    public static function clearAll(): void
    {
        // conservative: clear known dashboard / stat keys
        Cache::forget('sales_chart:7d');
        Cache::forget('dashboard:recent_activity');
        Cache::forget('dashboard:active_tables');
    }
}
