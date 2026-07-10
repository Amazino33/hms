<?php

namespace App\Filament\Widgets;

use App\Models\CountSession;
use App\Models\StaffDebt;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/**
 * Cumulative outstanding StaffDebt, shown persistently on the bartender/
 * chef's own dashboard — this constant visibility is the actual
 * behavioral mechanism of the handover accountability system, not just a
 * report. A shortfall charged at handover and then never seen again would
 * defeat the point.
 */
class BartenderDebtWidget extends BaseWidget
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
        $userId = auth()->id();

        $outstanding = Cache::remember("bartender_debt:{$userId}", 60, function () use ($userId) {
            return StaffDebt::where('user_id', $userId)
                ->whereIn('status', ['open', 'partially_settled'])
                ->get()
                ->sum(fn (StaffDebt $debt) => $debt->remainingBalance());
        });

        $openCount = Cache::remember("bartender_debt_count:{$userId}", 60, function () use ($userId) {
            return StaffDebt::where('user_id', $userId)
                ->whereIn('status', ['open', 'partially_settled'])
                ->count();
        });

        $handoverCount = Cache::remember("bartender_handover_count:{$userId}", 300, function () use ($userId) {
            return CountSession::where('outgoing_user_id', $userId)
                ->where('status', 'reviewed')
                ->count();
        });

        return [
            Stat::make('Outstanding Debt', '₦' . number_format($outstanding, 2))
                ->description($openCount > 0 ? "{$openCount} open debt(s)" : 'No open debts')
                ->descriptionIcon($outstanding > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($outstanding > 0 ? 'danger' : 'success'),

            Stat::make('Handovers Completed', (string) $handoverCount)
                ->description('As outgoing custodian')
                ->descriptionIcon('heroicon-m-arrow-path-rounded-square')
                ->color('gray'),
        ];
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->hasAnyRole(['bartender', 'chef', 'super_admin']);
    }
}
