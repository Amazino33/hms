<?php

namespace App\Filament\Widgets;

use App\Models\Commission;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class WaiterCommissionWidget extends BaseWidget
{
    // Defer loading — keep first-render fast.
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

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $userId   = auth()->id();
        $monthKey = now()->format('Y-m');

        // ── This Month ──────────────────────────────────────────────────────────
        $thisMonth = Cache::remember("commission:{$userId}:{$monthKey}", 60, function () use ($userId) {
            return Commission::where('user_id', $userId)
                ->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->sum('amount');
        });

        // ── Last Month (for trend description) ──────────────────────────────────
        $lastMonthKey = now()->subMonth()->format('Y-m');
        $lastMonth = Cache::remember("commission:{$userId}:{$lastMonthKey}", 300, function () use ($userId) {
            return Commission::where('user_id', $userId)
                ->whereYear('created_at', now()->subMonth()->year)
                ->whereMonth('created_at', now()->subMonth()->month)
                ->sum('amount');
        });

        // ── Order count this month ────────────────────────────────────────────
        $orderCount = Cache::remember("commission_orders:{$userId}:{$monthKey}", 60, function () use ($userId) {
            return Commission::where('user_id', $userId)
                ->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->count();
        });

        // ── All-time total ────────────────────────────────────────────────────
        $allTime = Cache::remember("commission_alltime:{$userId}", 300, function () use ($userId) {
            return Commission::where('user_id', $userId)->sum('amount');
        });

        // Trend vs last month
        $trend       = $thisMonth - $lastMonth;
        $trendLabel  = $trend >= 0
            ? '+₦' . number_format($trend) . ' vs last month'
            : '-₦' . number_format(abs($trend)) . ' vs last month';
        $trendColor  = $trend >= 0 ? 'success' : 'warning';
        $trendIcon   = $trend >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down';

        return [
            Stat::make('Commission This Month', '₦' . number_format($thisMonth, 2))
                ->description($trendLabel)
                ->descriptionIcon($trendIcon)
                ->color($trendColor),

            Stat::make('Orders This Month', (string) $orderCount)
                ->description('Orders with commission earned')
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color('info'),

            Stat::make('All-Time Commission', '₦' . number_format($allTime, 2))
                ->description('Total since account creation')
                ->descriptionIcon('heroicon-m-trophy')
                ->color('gray'),
        ];
    }

    /**
     * Visible to waiters and admins/managers — hidden from cashiers.
     */
    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->hasAnyRole(['waiter', 'porter', 'super_admin', 'admin', 'manager']);
    }
}
