<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Support\BusinessDay;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WaiterShiftStats extends BaseWidget
{
    // Defer loading until user sees the dashboard
    public bool $ready = false;

    public function load(): void
    {
        $this->ready = true;
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        if (! $this->ready) {
            return view('filament.widgets._deferred-placeholder');
        }

        return parent::render();
    }

    // Set polling to keep numbers fresh (every 30 seconds)
    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        // 1. Get stats for THIS USER for the current BUSINESS day — a 1am
        // order belongs to last night's trading, same as My History.
        // Cancelled/returned orders and return tickets never left the bar as
        // a sale, so they are neither sales nor credit the waiter owes.
        $userId = auth()->id();
        $dateKey = BusinessDay::today();
        [$start, $end] = BusinessDay::boundsFor($dateKey);
        $orders = Cache::remember("waiter_shift:{$userId}:{$dateKey}", 30, function () use ($userId, $start, $end) {
            return Order::query()
                ->where('user_id', $userId)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end)
                ->whereNotIn('status', ['cancelled', 'returned'])
                ->where('is_return', false)
                ->select(
                    DB::raw('SUM(total_amount) as expected'),
                    DB::raw('SUM(amount_paid) as collected'),
                    DB::raw('SUM(total_amount - amount_paid) as debt')
                )
                ->first();
        });

        // Handle case where no orders exist yet
        $expected = $orders->expected ?? 0;
        $collected = $orders->collected ?? 0;
        $debt = $orders->debt ?? 0;

        return [
            // STAT 1: EXPECTED REVENUE (Total Sales)
            Stat::make('Total Sales (Today)', '₦'.number_format($expected))
                ->description('Value of orders taken')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('gray'),

            // STAT 2: CASH COLLECTED (What should be in their pocket)
            Stat::make('Cash Collected', '₦'.number_format($collected))
                ->description('Money received today')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success') // Green = Good
                ->extraAttributes([
                    'class' => 'cursor-pointer',
                    'title' => 'This is the amount you should account for.',
                ]),

            // STAT 3: OUTSTANDING DEBT (Not Paid)
            Stat::make('Outstanding Debt', '₦'.number_format($debt))
                ->description('Credit given today')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($debt > 0 ? 'danger' : 'gray'), // Red if there is debt
        ];
    }

    public static function canView(): bool
    {
        // Only show if user is NOT an admin (or strictly if they are a waiter)
        // Adjust based on your role logic
        return ! auth()->user()->hasRole('admin');
    }
}
