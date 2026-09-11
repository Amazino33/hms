<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Services\StaffReportService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Support\BusinessDay;

class StaffCashSummary extends StatsOverviewWidget
{
    public bool $ready = false;

    public function load(): void
    {
        $this->ready = true;
    }

    protected ?string $pollingInterval = '30s';

    public function render(): \Illuminate\Contracts\View\View
    {
        if (! $this->ready) {
            return view('filament.widgets._deferred-placeholder');
        }

        return parent::render();
    }

    protected function getStats(): array
    {
        $user = Auth::user();
        $service = new StaffReportService();
        $ttl = 60; // seconds

        // "Today" here is the 9am-to-9am business day, not the calendar
        // day — otherwise a bartender's 1am sales drop off the figure they
        // are measured on, and the widget disagrees with every other report
        // in the app about what "today" covered.
        $businessDate = BusinessDay::today();

        // Bartender
        if ($user->hasRole('bartender')) {
            $data = Cache::remember("staff_cash:bartender:{$user->id}:{$businessDate}", $ttl, fn () => $service->expectedCashByDestination('bar', $businessDate, $businessDate));
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
            $data = Cache::remember("staff_cash:chef:{$user->id}:{$businessDate}", $ttl, fn () => $service->expectedCashByDestination('kitchen', $businessDate, $businessDate));
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
        if ($user->hasRole(['waiter'])) {
            $history = Cache::remember("staff_cash:waiter:{$user->id}:{$businessDate}", $ttl, fn () => $service->staffDailyHistory($user->id, $businessDate, $businessDate));
            $paymentsTotal = $history[$businessDate]['payments_total'] ?? 0;

            return [
                Stat::make('My Collected (Today)', '₦' . number_format($paymentsTotal))
                    ->description('Payments you handled')
                    ->color('success'),
            ];
        }

        return [];
    }
}
