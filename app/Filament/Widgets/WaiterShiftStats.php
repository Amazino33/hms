<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class WaiterShiftStats extends BaseWidget
{
    // Set polling to keep numbers fresh (every 30 seconds)
    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        // 1. Get stats for THIS USER for TODAY only
        $orders = Order::query()
            ->where('user_id', auth()->id()) // Only my orders
            ->whereDate('created_at', today()) // Only today
            ->select(
                DB::raw('SUM(total_amount) as expected'),
                DB::raw('SUM(amount_paid) as collected'),
                DB::raw('SUM(total_amount - amount_paid) as debt')
            )
            ->first();

        // Handle case where no orders exist yet
        $expected = $orders->expected ?? 0;
        $collected = $orders->collected ?? 0;
        $debt = $orders->debt ?? 0;

        return [
            // STAT 1: EXPECTED REVENUE (Total Sales)
            Stat::make('Total Sales (Today)', '₦' . number_format($expected))
                ->description('Value of orders taken')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('gray'),

            // STAT 2: CASH COLLECTED (What should be in their pocket)
            Stat::make('Cash Collected', '₦' . number_format($collected))
                ->description('Money received today')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success') // Green = Good
                ->extraAttributes([
                    'class' => 'cursor-pointer',
                    'title' => 'This is the amount you should account for.',
                ]),

            // STAT 3: OUTSTANDING DEBT (Not Paid)
            Stat::make('Outstanding Debt', '₦' . number_format($debt))
                ->description('Credit given today')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($debt > 0 ? 'danger' : 'gray'), // Red if there is debt
        ];
    }

    public static function canView(): bool
    {
        // Only show if user is NOT an admin (or strictly if they are a waiter)
        // Adjust based on your role logic
        return !auth()->user()->hasRole('admin'); 
    }
}