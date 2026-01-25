<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Services\StaffReportService;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class StaffCashSummary extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $user = Auth::user();
        $service = new StaffReportService();

        // Default date range: today
        $from = Carbon::today();
        $to = Carbon::today();

        // Bartender
        if ($user->hasRole('bartender')) {
            $data = $service->expectedCashByDestination('bar', $from, $to);
            return [
                Stat::make('Bar Expected', '₦' . number_format($data['expected']))
                    ->description('Due to Bar')
                    ->color('primary'),
                Stat::make('Bar Collected', '₦' . number_format($data['collected']))
                    ->description('Collected Today')
                    ->color('success'),
            ];
        }

        // Chef
        if ($user->hasRole('chef')) {
            $data = $service->expectedCashByDestination('kitchen', $from, $to);
            return [
                Stat::make('Kitchen Expected', '₦' . number_format($data['expected']))
                    ->description('Due to Kitchen')
                    ->color('warning'),
                Stat::make('Kitchen Collected', '₦' . number_format($data['collected']))
                    ->description('Collected Today')
                    ->color('success'),
            ];
        }

        // Waiter: show payments collected by this user today
        if ($user->hasRole('waiter')) {
            $history = $service->staffDailyHistory($user->id, $from, $to);
            $todayKey = $from->format('Y-m-d');
            $paymentsTotal = $history[$todayKey]['payments_total'] ?? 0;

            return [
                Stat::make('My Collected (Today)', '₦' . number_format($paymentsTotal))
                    ->description('Payments you handled')
                    ->color('success'),
            ];
        }

        return [];
    }
}
