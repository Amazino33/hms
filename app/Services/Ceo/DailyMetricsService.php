<?php

namespace App\Services\Ceo;

use App\Models\Booking;
use App\Models\DailyBusinessSnapshot;
use App\Models\Expense;
use App\Models\FolioLine;
use App\Models\IngredientTransaction;
use App\Models\InventoryTransaction;
use App\Models\OrderPayment;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\StaffDebtRepayment;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The single calculation layer behind every DailyBusinessSnapshot column —
 * called identically by the nightly snapshot job, the live "today" figure
 * on the dashboard, and any explorer drill-down that needs a same-day
 * total. This is what Part A's "a number can never disagree with itself
 * between surfaces" rule means in code: one method, three callers.
 *
 * Two different kinds of figure live here, and they are computed
 * differently on purpose:
 *
 * - Flow figures (revenue, cash collected, damages, expenses, debt
 *   movements) are sums of activity that happened *within* the business
 *   day — a plain whereBetween on the day's [start, end) instants.
 * - The Gap components are *positions* — "how much is still outstanding
 *   as of the moment this day closed" — reconstructed as of a single
 *   instant (the day's close) rather than summed over the day, since an
 *   outstanding balance doesn't reset at midnight. For "today" (still in
 *   progress), the day's close instant is in the future, which is
 *   harmless: nothing has a timestamp beyond now() yet, so the same
 *   query naturally reads as "right now".
 */
class DailyMetricsService
{
    public function __construct(
        private readonly RevenueReportService $revenue = new RevenueReportService(),
        private readonly OccupancyReportService $occupancy = new OccupancyReportService(),
    ) {
    }

    public function forBusinessDate(string $businessDate): array
    {
        [$start, $end] = BusinessDay::boundsFor($businessDate);
        $dayRange = new DateRange(CarbonImmutable::parse($businessDate), CarbonImmutable::parse($businessDate));
        $asOf = $end->subSecond();

        $lineItems = $this->revenue->lineItems($dayRange);
        $revenueSummary = $this->revenue->summary($lineItems);
        $mix = $this->revenue->revenueMix($dayRange);

        $damages = $this->damagesCostTotal($start, $end);
        $cash = $this->cashCollected($start, $end);
        $gap = $this->gapAsOf($asOf);
        $debtMovements = $this->staffDebtMovements($start, $end);
        $occSummary = $this->occupancy->summary($dayRange);

        $expenses = (float) Expense::notVoided()->whereDate('date_incurred', $businessDate)->sum('amount');

        return [
            'business_date' => $businessDate,
            'revenue_earned_total' => $mix['total'],
            'revenue_bar' => $mix['bar'],
            'revenue_restaurant' => $mix['restaurant'],
            'revenue_rooms' => $mix['rooms'],
            'cogs_total' => $revenueSummary['cost'],
            'cogs_estimated_count' => $revenueSummary['cost_estimated_count'],
            'gross_profit' => round($mix['total'] - $revenueSummary['cost'] - $damages, 2),
            'damages_cost_total' => $damages,
            'cash_collected_total' => $cash['total'],
            'cash_collected_cash' => $cash['cash'],
            'cash_collected_pos' => $cash['pos'],
            'cash_collected_transfers_verified' => $cash['transfers_verified'],
            'cash_collected_transfers_unverified' => $cash['transfers_unverified'],
            'gap_total' => $gap['total'],
            'gap_unverified_transfers' => $gap['unverified_transfers'],
            'gap_open_folio_balance' => $gap['open_folio_balance'],
            'gap_unsettled_shift_amount' => $gap['unsettled_shift_amount'],
            'gap_staff_debt_outstanding' => $gap['staff_debt_outstanding'],
            'staff_debt_new' => $debtMovements['new'],
            'staff_debt_repaid' => $debtMovements['repaid'],
            'expenses_total' => $expenses,
            'rooms_occupied' => (int) $occSummary['room_nights_sold'],
            'occupancy_rate' => $occSummary['average_occupancy_pct'],
            'adr' => $occSummary['adr'],
            'computed_at' => now(),
        ];
    }

    /**
     * One row per business day across the range — closed days read from
     * their DailyBusinessSnapshot (no recomputation), today (if included)
     * is computed live. This is the Performance section's rule in code:
     * "range charts over closed days read only from snapshots".
     *
     * A closed day with no snapshot row yet (never backfilled) is simply
     * omitted rather than silently computed live — a dashboard load must
     * never trigger the same heavy per-day computation the nightly job
     * exists to avoid; run the backfill command instead.
     */
    public function rangeSeries(DateRange $range): Collection
    {
        $today = BusinessDay::today();
        $labels = collect($range->eachDate())->map(fn (CarbonImmutable $d) => $d->toDateString());

        $snapshots = DailyBusinessSnapshot::latestForRange($labels->first(), $labels->last())
            ->keyBy(fn (DailyBusinessSnapshot $s) => $s->business_date->toDateString());

        return $labels->map(function (string $date) use ($today, $snapshots) {
            if ($date === $today) {
                return $this->forBusinessDate($date);
            }

            $snapshot = $snapshots->get($date);

            return $snapshot ? $this->fromSnapshot($snapshot) : null;
        })->filter()->values();
    }

    private function fromSnapshot(DailyBusinessSnapshot $s): array
    {
        return [
            'business_date' => $s->business_date->toDateString(),
            'revenue_earned_total' => (float) $s->revenue_earned_total,
            'revenue_bar' => (float) $s->revenue_bar,
            'revenue_restaurant' => (float) $s->revenue_restaurant,
            'revenue_rooms' => (float) $s->revenue_rooms,
            'cogs_total' => (float) $s->cogs_total,
            'cogs_estimated_count' => (int) $s->cogs_estimated_count,
            'gross_profit' => (float) $s->gross_profit,
            'damages_cost_total' => (float) $s->damages_cost_total,
            'cash_collected_total' => (float) $s->cash_collected_total,
            'cash_collected_cash' => (float) $s->cash_collected_cash,
            'cash_collected_pos' => (float) $s->cash_collected_pos,
            'cash_collected_transfers_verified' => (float) $s->cash_collected_transfers_verified,
            'cash_collected_transfers_unverified' => (float) $s->cash_collected_transfers_unverified,
            'gap_total' => (float) $s->gap_total,
            'gap_unverified_transfers' => (float) $s->gap_unverified_transfers,
            'gap_open_folio_balance' => (float) $s->gap_open_folio_balance,
            'gap_unsettled_shift_amount' => (float) $s->gap_unsettled_shift_amount,
            'gap_staff_debt_outstanding' => (float) $s->gap_staff_debt_outstanding,
            'staff_debt_new' => (float) $s->staff_debt_new,
            'staff_debt_repaid' => (float) $s->staff_debt_repaid,
            'expenses_total' => (float) $s->expenses_total,
            'rooms_occupied' => (int) $s->rooms_occupied,
            'occupancy_rate' => (float) $s->occupancy_rate,
            'adr' => (float) $s->adr,
            'computed_at' => $s->computed_at,
        ];
    }

    /**
     * Cash/POS/verified-transfer/unverified-transfer split for payments
     * collected within [$start, $end). 'split' order payments (cash+POS
     * combined, no per-component breakdown stored — confirmed no transfer
     * leg exists in a split payment at this venue) are folded into the
     * 'cash' bucket by convention, so the four components still sum
     * exactly to the total; there is no finer-grained source to split
     * them further.
     */
    private function cashCollected(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $cash = 0.0;
        $pos = 0.0;
        $transfersVerified = 0.0;
        $transfersUnverified = 0.0;

        $orderPayments = OrderPayment::whereBetween('paid_at', [$start, $end->subSecond()])
            ->get(['amount', 'method', 'verified']);

        foreach ($orderPayments as $payment) {
            $amount = (float) $payment->amount;

            match (true) {
                $payment->method === 'transfer' => $payment->verified ? $transfersVerified += $amount : $transfersUnverified += $amount,
                $payment->method === 'pos' => $pos += $amount,
                default => $cash += $amount,
            };
        }

        $folioPayments = FolioLine::where('type', 'payment')
            ->whereBetween('created_at', [$start, $end->subSecond()])
            ->get(['amount', 'payment_method', 'verified']);

        foreach ($folioPayments as $line) {
            $amount = abs((float) $line->amount);

            match (true) {
                $line->payment_method === 'transfer' => $line->verified ? $transfersVerified += $amount : $transfersUnverified += $amount,
                in_array($line->payment_method, ['pos_terminal', 'pos'], true) => $pos += $amount,
                default => $cash += $amount,
            };
        }

        return [
            'total' => round($cash + $pos + $transfersVerified + $transfersUnverified, 2),
            'cash' => round($cash, 2),
            'pos' => round($pos, 2),
            'transfers_verified' => round($transfersVerified, 2),
            'transfers_unverified' => round($transfersUnverified, 2),
        ];
    }

    private function damagesCostTotal(CarbonImmutable $start, CarbonImmutable $end): float
    {
        $productCost = (float) InventoryTransaction::where('type', 'damage_write_off')
            ->whereBetween('created_at', [$start, $end->subSecond()])
            ->get(['quantity', 'cost_per_unit'])
            ->sum(fn (InventoryTransaction $t) => (float) $t->quantity * (float) ($t->cost_per_unit ?? 0));

        $ingredientCost = (float) IngredientTransaction::where('type', 'damage_write_off')
            ->whereBetween('created_at', [$start, $end->subSecond()])
            ->get(['quantity', 'cost_per_unit'])
            ->sum(fn (IngredientTransaction $t) => (float) $t->quantity * (float) ($t->cost_per_unit ?? 0));

        return round($productCost + $ingredientCost, 2);
    }

    private function staffDebtMovements(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [
            'new' => round((float) StaffDebt::whereBetween('created_at', [$start, $end->subSecond()])->sum('amount'), 2),
            'repaid' => round((float) StaffDebtRepayment::whereBetween('created_at', [$start, $end->subSecond()])->sum('amount'), 2),
        ];
    }

    /**
     * The four Gap components, each reconstructed as an outstanding
     * position "as of" a single instant rather than summed over a range.
     * gap_total is defined as their sum — not independently derived — so
     * the two can never disagree.
     */
    private function gapAsOf(CarbonImmutable $asOf): array
    {
        $unverifiedTransfers = $this->unverifiedTransfersAsOf($asOf);
        $openFolioBalance = $this->openFolioBalanceAsOf($asOf);
        $unsettledShiftAmount = $this->unsettledShiftAmountAsOf($asOf);
        $staffDebtOutstanding = $this->staffDebtOutstandingAsOf($asOf);

        return [
            'unverified_transfers' => round($unverifiedTransfers, 2),
            'open_folio_balance' => round($openFolioBalance, 2),
            'unsettled_shift_amount' => round($unsettledShiftAmount, 2),
            'staff_debt_outstanding' => round($staffDebtOutstanding, 2),
            'total' => round($unverifiedTransfers + $openFolioBalance + $unsettledShiftAmount + $staffDebtOutstanding, 2),
        ];
    }

    /**
     * A transfer counts as "unverified as of $asOf" if it was collected by
     * then but had not yet been verified (or ruled, for OrderPayment,
     * which has a dispute-resolution path FolioLine doesn't) by then —
     * i.e. it reads as still-open exactly as it would have looked to
     * someone checking the books at that instant.
     */
    private function unverifiedTransfersAsOf(CarbonImmutable $asOf): float
    {
        $orderTotal = (float) OrderPayment::where('method', 'transfer')
            ->where('paid_at', '<=', $asOf)
            ->where(fn ($q) => $q->where('verified', false)->orWhere('verified_at', '>', $asOf))
            ->where(fn ($q) => $q->whereNull('ruled_at')->orWhere('ruled_at', '>', $asOf))
            ->sum('amount');

        $folioTotal = (float) FolioLine::where('type', 'payment')
            ->where('payment_method', 'transfer')
            ->where('created_at', '<=', $asOf)
            ->where(fn ($q) => $q->where('verified', false)->orWhere('verified_at', '>', $asOf))
            ->get(['amount'])
            ->sum(fn (FolioLine $l) => abs((float) $l->amount));

        return $orderTotal + $folioTotal;
    }

    /**
     * Sum of folio balances for bookings that were in-house at $asOf
     * (checked in, not yet checked out), counting only lines posted by
     * then — a folio's balance grows through a stay, so "open balance as
     * of last Tuesday" must not include Wednesday's room-service order.
     * At most ~34 in-house bookings at once, so the per-booking query is
     * not a performance concern at this venue's scale.
     */
    private function openFolioBalanceAsOf(CarbonImmutable $asOf): float
    {
        return (float) Booking::whereNotNull('checked_in_at')
            ->where('checked_in_at', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('checked_out_at')->orWhere('checked_out_at', '>', $asOf))
            ->with('folio')
            ->get()
            ->sum(function (Booking $b) use ($asOf) {
                if (! $b->folio) {
                    return 0.0;
                }

                return (float) $b->folio->lines()->where('created_at', '<=', $asOf)->sum('amount');
            });
    }

    /**
     * Declared (staff-counted, not yet cashier-confirmed) cash+POS for
     * shifts that had ended by $asOf but were not yet settled by then.
     */
    private function unsettledShiftAmountAsOf(CarbonImmutable $asOf): float
    {
        return (float) Shift::whereNotNull('ended_at')
            ->where('ended_at', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('settled_at')->orWhere('settled_at', '>', $asOf))
            ->get(['declared_cash', 'declared_pos'])
            ->sum(fn (Shift $s) => (float) $s->declared_cash + (float) $s->declared_pos);
    }

    private function staffDebtOutstandingAsOf(CarbonImmutable $asOf): float
    {
        return (float) StaffDebt::where('created_at', '<=', $asOf)
            ->with(['repayments' => fn ($q) => $q->where('created_at', '<=', $asOf)])
            ->get()
            ->sum(function (StaffDebt $d) {
                $repaid = (float) $d->repayments->sum('amount');

                return max(0.0, (float) $d->amount - $repaid);
            });
    }
}
