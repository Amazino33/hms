<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Services\StaffReportService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

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

        // Default date range: today
        $from = Carbon::today();
        $to = Carbon::today();

        // Bartender
        if ($user->hasRole('bartender')) {
            $data = Cache::remember("staff_cash:bartender:{$user->id}", $ttl, fn () => $service->expectedCashByDestination('bar', $from, $to));
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
            $data = Cache::remember("staff_cash:chef:{$user->id}", $ttl, fn () => $service->expectedCashByDestination('kitchen', $from, $to));
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
            $history = Cache::remember("staff_cash:waiter:{$user->id}", $ttl, fn () => $service->staffDailyHistory($user->id, $from, $to));
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
