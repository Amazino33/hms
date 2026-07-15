<?php

namespace App\Services\Ceo;

use App\Models\StaffDebt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Consolidates StaffDebt across every source (waiter shift shortfall,
 * bartender/receptionist/cashier-session shortfalls, ...) — schema-open
 * to whatever `reason` values exist, never a hardcoded list, so a future
 * source needs no change here.
 */
class LeakageReportService
{
    /**
     * @param array{user_id?: int, reason?: string, status?: string} $filters status: outstanding|repaid|all
     */
    public function perStaffRows(DateRange $range, array $filters = []): Collection
    {
        $debts = $this->scopedQuery($filters)
            ->whereBetween('created_at', [$range->startBoundary(), $range->endBoundary()])
            ->with(['repayments', 'user'])
            ->get();

        $now = CarbonImmutable::now();

        return $debts->groupBy('user_id')->map(function (Collection $userDebts) use ($now) {
            $user = $userDebts->first()->user;
            $incurred = (float) $userDebts->sum('amount');
            $repaid = (float) $userDebts->sum(fn (StaffDebt $d) => $d->totalRepaid());

            $aging = ['aging_0_7' => 0.0, 'aging_8_30' => 0.0, 'aging_30_plus' => 0.0];
            $outstanding = 0.0;

            foreach ($userDebts as $debt) {
                $remaining = $debt->remainingBalance();
                $outstanding += $remaining;

                if ($remaining <= 0.0) {
                    continue;
                }

                $ageDays = $debt->created_at->diffInDays($now);
                $bucket = $ageDays <= 7 ? 'aging_0_7' : ($ageDays <= 30 ? 'aging_8_30' : 'aging_30_plus');
                $aging[$bucket] += $remaining;
            }

            return array_merge([
                'user_id' => $user?->id,
                'user_name' => $user?->name ?? 'Unknown',
                'debts_incurred_count' => $userDebts->count(),
                'debts_incurred_amount' => $incurred,
                'repaid' => $repaid,
                'outstanding' => $outstanding,
                'repayment_ratio_pct' => $incurred > 0 ? round($repaid / $incurred * 100, 2) : 0.0,
            ], $aging);
        })->sortByDesc('outstanding')->values();
    }

    public function summary(DateRange $range, array $filters = []): array
    {
        $rows = $this->perStaffRows($range, $filters);
        $incurred = (float) $rows->sum('debts_incurred_amount');
        $repaid = (float) $rows->sum('repaid');

        $resolver = new DateRangeResolver();
        $previousRange = $resolver->resolveComparison('previous_period', $range);
        $previousIncurred = $previousRange
            ? (float) $this->scopedQuery($filters)
                ->whereBetween('created_at', [$previousRange->startBoundary(), $previousRange->endBoundary()])
                ->sum('amount')
            : 0.0;

        return [
            'total_incurred' => $incurred,
            'total_repaid' => $repaid,
            'total_outstanding_now' => $this->totalOutstandingNow($filters),
            'repayment_ratio_pct' => $incurred > 0 ? round($repaid / $incurred * 100, 2) : 0.0,
            'trend' => $resolver->delta($incurred, $previousIncurred),
        ];
    }

    /**
     * Current-state figure ("as of now") — respects staff/source filters
     * but deliberately ignores the date range, since outstanding debt
     * doesn't care when it was incurred.
     */
    public function totalOutstandingNow(array $filters = []): float
    {
        $filters = array_diff_key($filters, ['status' => null]);

        return (float) $this->scopedQuery($filters)
            ->with('repayments')
            ->get()
            ->sum(fn (StaffDebt $d) => $d->remainingBalance());
    }

    /**
     * Current-state (as-of-now) aging across every staff member combined —
     * not scoped to debts incurred in any particular period, since an old
     * debt's age keeps growing regardless of when the report is run.
     */
    public function currentAgingBreakdown(array $filters = []): array
    {
        $debts = $this->scopedQuery(array_diff_key($filters, ['status' => null]))->with('repayments')->get();
        $now = CarbonImmutable::now();
        $aging = ['aging_0_7' => 0.0, 'aging_8_30' => 0.0, 'aging_30_plus' => 0.0];

        foreach ($debts as $debt) {
            $remaining = $debt->remainingBalance();

            if ($remaining <= 0.0) {
                continue;
            }

            $ageDays = $debt->created_at->diffInDays($now);
            $bucket = $ageDays <= 7 ? 'aging_0_7' : ($ageDays <= 30 ? 'aging_8_30' : 'aging_30_plus');
            $aging[$bucket] += $remaining;
        }

        return $aging;
    }

    public function debtsForUser(int $userId, ?DateRange $range = null): Collection
    {
        $query = StaffDebt::where('user_id', $userId)->with(['repayments', 'shift', 'order']);

        if ($range) {
            $query->whereBetween('created_at', [$range->startBoundary(), $range->endBoundary()]);
        }

        return $query->orderByDesc('created_at')->get();
    }

    private function scopedQuery(array $filters)
    {
        $query = StaffDebt::query();

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['reason'])) {
            $query->where('reason', $filters['reason']);
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $filters['status'] === 'repaid'
                ? $query->where('status', 'settled')
                : $query->whereIn('status', ['open', 'partially_settled']);
        }

        return $query;
    }
}
