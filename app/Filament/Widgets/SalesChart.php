<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\Order;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;
use Illuminate\Support\Facades\Cache;

class SalesChart extends ChartWidget
{
    protected ?string $heading = 'Revenue (Last 7 Days)';
    
    // Defer heavy load until client init
    public bool $ready = false;

    public function load(): void
    {
        $this->ready = true;
    }

    // Refresh chart every 30s to reduce DB load (data changes slower)
    protected ?string $pollingInterval = '30s';
    
    // Sort order: Higher number = lower on the dashboard
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        // Only allow Admins to see this widget
        return auth()->user()->hasRole(['super_admin', 'manager']);
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        if (! $this->ready) {
            return view('filament.widgets._deferred-placeholder');
        }

        return parent::render();
    }

    protected function getData(): array
    {
        $data = Cache::remember('sales_chart:7d', 60, function () {
            return Trend::model(Order::class)
                ->between(
                    start: now()->subDays(7),
                    end: now(),
                )
                ->perDay()
                ->sum('total_amount');
        });

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