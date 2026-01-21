<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\Order;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;

class SalesChart extends ChartWidget
{
    protected ?string $heading = 'Revenue (Last 7 Days)';
    
    // Refresh chart every 15 seconds to match the Stats Overview
    protected ?string $pollingInterval = '15s';
    
    // Sort order: Higher number = lower on the dashboard
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        // Only allow Admins to see this widget
        return auth()->user()->hasRole(['super_admin', 'manager']);
    }

    protected function getData(): array
    {
        // 1. Use the Trend Package to group data automatically
        $data = Trend::model(Order::class)
            ->between(
                start: now()->subDays(7),
                end: now(),
            )
            ->perDay()
            ->sum('total_amount'); // Sums the 'total_amount' column

        // 2. Format for Chart.js
        return [
            'datasets' => [
                [
                    'label' => 'Daily Sales (₦)',
                    'data' => $data->map(fn (TrendValue $value) => $value->aggregate),
                    'borderColor' => '#f59e0b', // Amber-500
                    'backgroundColor' => 'rgba(245, 158, 11, 0.2)', // Transparent Amber
                    'fill' => true,
                ],
            ],
            'labels' => $data->map(fn (TrendValue $value) => $value->date),
        ];
    }

    protected function getType(): string
    {
        return 'line'; // 'line' looks better for trends, 'bar' is good for comparison
    }
}