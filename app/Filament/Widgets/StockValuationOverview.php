<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

class StockValuationOverview extends BaseWidget
{
    public static function canView(): bool
    {
        // Only allow Admins to see this widget
        return auth()->user()->hasRole(['super_admin', 'manager']);
    }

    protected function getStats(): array
    {
        $totals = Cache::remember('stock_valuation:totals', 120, function () {
            $products = Product::withSum('inventory', 'quantity')->get();

            $totalCost = $products->sum(fn($p) => $p->cost_price * $p->inventory_sum_quantity);
            $totalRevenue = $products->sum(fn($p) => $p->price * $p->inventory_sum_quantity);
            $potentialProfit = $totalRevenue - $totalCost;
            $totalItems = $products->sum('inventory_sum_quantity');

            return compact('totalCost', 'totalRevenue', 'potentialProfit', 'totalItems');
        });

        $totalCost = $totals['totalCost'] ?? 0;
        $totalRevenue = $totals['totalRevenue'] ?? 0;
        $potentialProfit = $totals['potentialProfit'] ?? 0;
        $totalItems = $totals['totalItems'] ?? 0;

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