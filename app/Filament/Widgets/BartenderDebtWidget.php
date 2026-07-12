<?php

namespace App\Filament\Widgets;

use App\Models\CountSession;
use App\Models\HandoverDiscrepancy;
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

        // Confirmed Debt and Pending Shortages are deliberately NOT cached
        // (unlike the slower-changing handover count below) — the whole
        // behavioral mechanism here is that a debit or a fresh seal shows up
        // the instant it happens, not up to 60s later on a stale figure.
        // Both are cheap single-user aggregates, so there's no real cost to
        // computing them fresh on every render.
        $outstanding = StaffDebt::where('user_id', $userId)
            ->whereIn('status', ['open', 'partially_settled'])
            ->get()
            ->sum(fn (StaffDebt $debt) => $debt->remainingBalance());

        $openCount = StaffDebt::where('user_id', $userId)
            ->whereIn('status', ['open', 'partially_settled'])
            ->count();

        $handoverCount = Cache::remember("bartender_handover_count:{$userId}", 300, function () use ($userId) {
            return CountSession::where('outgoing_user_id', $userId)
                ->where('status', 'reviewed')
                ->count();
        });

        // Every shortage flagged at a handover this person was outgoing
        // custodian on, still awaiting a manager's recount/debit/pend/
        // write-off decision — separate from Confirmed Debt (already-
        // decided StaffDebt), since a pending amount is not yet certain to
        // become a debt at all.
        $pendingQuery = HandoverDiscrepancy::whereIn('status', ['pending_resolution', 'pending_investigation'])
            ->whereHas('item.session', fn ($q) => $q->where('outgoing_user_id', $userId));
        $pendingShortages = (float) $pendingQuery->sum('naira_value');
        $pendingCount = $pendingQuery->count();

        return [
            Stat::make('Confirmed Debt', '₦' . number_format($outstanding, 2))
                ->description($openCount > 0 ? "{$openCount} open debt(s)" : 'No open debts')
                ->descriptionIcon($outstanding > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($outstanding > 0 ? 'danger' : 'success'),

            Stat::make('Pending Shortages', '₦' . number_format($pendingShortages, 2))
                ->description($pendingCount > 0 ? "{$pendingCount} awaiting manager decision" : 'Nothing pending')
                ->descriptionIcon($pendingShortages > 0 ? 'heroicon-m-clock' : 'heroicon-m-check-circle')
                ->color($pendingShortages > 0 ? 'warning' : 'success'),

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
