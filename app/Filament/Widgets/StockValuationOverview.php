<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Product;

class StockValuationOverview extends BaseWidget
{
    public static function canView(): bool
    {
        // Only allow Admins to see this widget
        return auth()->user()->hasRole(['super_admin', 'manager']);
    }

    protected function getStats(): array
    {
        // 1. Get products with inventory sums
        $products = Product::withSum('inventory', 'quantity')->get();

        // 2. Calculate Totals
        $totalCost = $products->sum(fn($p) => $p->cost_price * $p->inventory_sum_quantity);
        $totalRevenue = $products->sum(fn($p) => $p->price * $p->inventory_sum_quantity);
        $potentialProfit = $totalRevenue - $totalCost;
        $totalItems = $products->sum('inventory_sum_quantity');

        return [
            Stat::make('Total Cost', '₦' . number_format($totalCost))
                ->color('danger'),
            Stat::make('Potential Revenue', '₦' . number_format($totalRevenue))
                ->color('success'),
            Stat::make('Potential Profit', '₦' . number_format($potentialProfit))
                ->color('primary'),
            Stat::make('Total Items', $totalItems)
                ->color('warning'),
        ];
    }
}