<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use App\Models\Order;
use App\Models\Booking;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class StatOverview extends StatsOverviewWidget
{
    // Defer heavy calculations until client init
    public bool $ready = false;

    public function load(): void
    {
        $this->ready = true;
    }

    // Poll a bit slower to reduce DB churn while still feeling live
    protected ?string $pollingInterval = '20s';

    public function render(): \Illuminate\Contracts\View\View
    {
        if (! $this->ready) {
            return view('filament.widgets._deferred-placeholder');
        }

        return parent::render();
    }

    protected function getStats(): array
    {
        $user = auth()->user();

        // Define common date variables used across different user roles
        $todayKey = Carbon::today()->toDateString();
        $monthKey = Carbon::now()->format('Y-m');

        // 👑 SCENARIO 1: ADMIN & MANAGER (See Money)
        if ($user->hasRole(['super_admin', 'manager'])) {

            $restaurantRevenue = Cache::remember("stat:restaurant_revenue:{$todayKey}", 30, fn () =>
                Order::whereDate('created_at', $todayKey)
                    ->where('status', '!=', 'cancelled')
                    ->sum('total_amount')
            );

            $hotelRevenue = Cache::remember("stat:hotel_revenue:{$monthKey}", 60, fn () =>
                Booking::whereMonth('created_at', Carbon::now()->month)
                    ->sum('total_price')
            );

            [$totalRooms, $occupiedRooms] = Cache::remember('stat:rooms', 60, fn () => [
                Room::count(),
                Room::where('status', 'occupied')->count(),
            ]);

            return [
                Stat::make('Restaurant Sales (Today)', '₦' . number_format($restaurantRevenue))
                    ->description('Orders from Bar & Kitchen')
                    ->descriptionIcon('heroicon-m-arrow-trending-up')
                    ->color('success')
                    ->chart([7, 2, 10, 3, 15, 4, 17]), // Dummy chart data for visual effect

                Stat::make('Hotel Revenue (This Month)', '₦' . number_format($hotelRevenue))
                    ->description('Total Booking Value')
                    ->descriptionIcon('heroicon-m-building-office')
                    ->color('primary'),

                Stat::make('Room Occupancy', "$occupiedRooms / $totalRooms")
                    ->description('Currently Occupied Rooms')
                    ->color($occupiedRooms >= $totalRooms ? 'danger' : 'warning'), // Red if full
            ];
        }

        // 👨‍🍳 SCENARIO 2: CHEF (See Workload)
        if ($user->hasRole('chef')) {
            $pending = Cache::remember('stat:chef:pending', 20, fn () => \App\Models\Order::where('status', 'pending')->count());
            $preparing = Cache::remember('stat:chef:preparing', 20, fn () => \App\Models\Order::where('status', 'preparing')->count());

            return [
                Stat::make('New Orders', $pending)
                    ->description('Waiting for acceptance')
                    ->color('danger')
                    ->descriptionIcon('heroicon-m-bell-alert'),

                Stat::make('Cooking Now', $preparing)
                    ->description('Currently on fire')
                    ->color('warning'),
            ];
        }

        // 🤵 SCENARIO 3: WAITER (See Personal Stats)
        if ($user->hasRole('waiter')) {
            
            // 1. "My Ready Orders" (Only show orders created by THIS waiter that are ready)
            $cacheKeyReady = "stat:waiter:ready:{$user->id}";
            $cacheKeySales = "stat:waiter:sales:{$user->id}:{$todayKey}";

            $myReady = Cache::remember($cacheKeyReady, 20, fn () =>
                \App\Models\Order::where('user_id', $user->id)
                    ->where('status', 'ready')
                    ->count()
            );

            $mySales = Cache::remember($cacheKeySales, 20, fn () =>
                \App\Models\Order::where('user_id', $user->id)
                    ->whereDate('created_at', Carbon::today())
                    ->sum('total_amount')
            );

            return [
                Stat::make('Ready for Pickup', $myReady)
                    ->description('Your tables waiting')
                    ->color('success')
                    ->descriptionIcon('heroicon-m-check-badge'),
            ];
        }

        return [];
    }
}
