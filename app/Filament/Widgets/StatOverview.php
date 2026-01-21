<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use App\Models\Order;
use App\Models\Booking;
use App\Models\Room;
use Carbon\Carbon;

class StatOverview extends StatsOverviewWidget
{
    // Poll updates every 15 seconds so the dashboard feels "live"
    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $user = auth()->user();

        // 👑 SCENARIO 1: ADMIN & MANAGER (See Money)
        if ($user->hasRole(['super_admin', 'manager'])) {
            // 1. Calculate Restaurant Revenue (Today)
            $restaurantRevenue = Order::whereDate('created_at', Carbon::today())
                ->where('status', '!=', 'cancelled') // Exclude cancelled
                ->sum('total_amount');

            // 2. Calculate Hotel Revenue (This Month)
            $hotelRevenue = Booking::whereMonth('created_at', Carbon::now()->month)
                ->sum('total_price');

            // 3. Check Room Occupancy
            $totalRooms = Room::count();
            $occupiedRooms = Room::where('status', 'occupied')->count();

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
            $pending = \App\Models\Order::where('status', 'pending')->count();
            $preparing = \App\Models\Order::where('status', 'preparing')->count();

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

        // 🤵 SCENARIO 3: WAITER (See Service Status)
        if ($user->hasRole('waiter')) {
            $ready = \App\Models\Order::where('status', 'ready')->count();
            
            return [
                Stat::make('Ready to Serve', $ready)
                    ->description('Waiting for pickup')
                    ->color('success')
                    ->descriptionIcon('heroicon-m-check-badge'),
                    
                Stat::make('My Sales Today', '₦' . number_format(\App\Models\Order::whereDate('created_at', now())->sum('total_amount')))
                    ->description('Total value of orders')
                    ->color('gray'),
            ];
        }

        return [];
    }
}
