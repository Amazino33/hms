<?php

namespace App\Services\Ceo;

use App\Models\Commission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Per-waiter and per-shift settlement reporting. Shortfall figures are
 * read from the StaffDebt row CashierSettlementService::finalizeIfComplete()
 * already created (reason='shift_shortfall', amount = cash shortfall +
 * any supervisor-charged flags combined) — this service never
 * re-derives a shortfall from cash_variance itself, since the debt row
 * is already the authoritative combined figure.
 *
 * Every view of this data (per-shift rows, the all-waiters overview, and
 * the summary card) is deliberately derived from the SAME confirmed-shift
 * source, keyed on each shift's ended_at falling inside the selected
 * DateRange. Earlier, the all-waiters overview instead queried raw orders
 * by created_at and debts by their own created_at independently, which
 * could disagree with the per-shift figures for the exact same waiter and
 * date range (e.g. a shift ending just after the range's cutoff, or a
 * shift still open/unconfirmed) — that mismatch is exactly what "the date
 * range gave us issues" reports were catching. Deriving everything from
 * perShiftRows() means both tabs always agree.
 */
class WaiterLedgerService
{
    public function perShiftRows(int $waiterId, DateRange $range): Collection
    {
        $shifts = Shift::query()
            ->where('user_id', $waiterId)
            ->where('type', 'waiter')
            ->where('status', 'confirmed')
            ->whereBetween('ended_at', [$range->startBoundary(), $range->endBoundary()])
            ->orderBy('ended_at')
            ->get();

        return $shifts->map(function (Shift $shift) use ($waiterId) {
            $orderIds = Order::where('shift_id', $shift->id)
                ->where('is_return', false)
                ->where('status', '!=', 'cancelled')
                ->pluck('id');

            $totalSales = (float) OrderItem::whereIn('order_id', $orderIds)->sum('subtotal');
            $commission = (float) Commission::whereIn('order_id', $orderIds)->sum('amount');

            $paymentsByMethod = OrderPayment::where('shift_id', $shift->id)->get()->groupBy('method');
            $shortfall = (float) StaffDebt::where('shift_id', $shift->id)->where('reason', 'shift_shortfall')->sum('amount');

            return [
                'shift_id' => $shift->id,
                'date' => $shift->ended_at,
                'started_at' => $shift->started_at,
                'orders_count' => $orderIds->count(),
                'total_sales' => $totalSales,
                'commission' => $commission,
                'cash_declared' => (float) ($paymentsByMethod->get('cash')?->sum('amount') ?? 0),
                'pos_total' => (float) ($paymentsByMethod->get('pos')?->sum('amount') ?? 0),
                'transfer_total' => (float) ($paymentsByMethod->get('transfer')?->sum('amount') ?? 0),
                'shortfall' => $shortfall,
                'shortfall_rate_pct' => $totalSales > 0 ? round($shortfall / $totalSales * 100, 2) : 0.0,
                'running_debt_balance' => $this->debtBalanceAsOf($waiterId, $shift->ended_at),
            ];
        });
    }

    /**
     * One row per order within the waiter's confirmed shifts for the
     * period — the order-level detail the shift rows only summarize.
     */
    public function orderRows(int $waiterId, DateRange $range): Collection
    {
        $shiftIds = Shift::query()
            ->where('user_id', $waiterId)
            ->where('type', 'waiter')
            ->where('status', 'confirmed')
            ->whereBetween('ended_at', [$range->startBoundary(), $range->endBoundary()])
            ->pluck('id');

        return Order::whereIn('shift_id', $shiftIds)
            ->where('is_return', false)
            ->where('status', '!=', 'cancelled')
            ->with('commission')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Order $order) => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'date' => $order->created_at,
                'status' => $order->status,
                'total_amount' => (float) $order->total_amount,
                'commission' => (float) ($order->commission?->amount ?? 0),
            ]);
    }

    /**
     * Every debt this waiter incurred in the period, across all reasons
     * (shift_shortfall, unpaid_order_conversion, manual) — the per-shift
     * "shortfall" figure above only ever counts shift_shortfall, so a
     * manual or unpaid-order-conversion debt would otherwise never show
     * up anywhere in the period figures despite counting toward the
     * outstanding balance.
     */
    public function debtRows(int $waiterId, DateRange $range): Collection
    {
        return StaffDebt::where('user_id', $waiterId)
            ->whereBetween('created_at', [$range->startBoundary(), $range->endBoundary()])
            ->with('repayments')
            ->orderBy('created_at')
            ->get()
            ->map(fn (StaffDebt $debt) => [
                'id' => $debt->id,
                'date' => $debt->created_at,
                'reason' => $debt->reason,
                'amount' => (float) $debt->amount,
                'repaid' => $debt->totalRepaid(),
                'remaining' => $debt->remainingBalance(),
                'status' => $debt->status,
                'notes' => $debt->notes,
            ]);
    }

    public function summary(int $waiterId, DateRange $range): array
    {
        $rows = $this->perShiftRows($waiterId, $range);
        $totalSales = (float) $rows->sum('total_sales');
        $totalShortfall = (float) $rows->sum('shortfall');

        return [
            'total_sales_handled' => $totalSales,
            'total_shortfall' => $totalShortfall,
            'shortfall_rate_pct' => $totalSales > 0 ? round($totalShortfall / $totalSales * 100, 2) : 0.0,
            'orders_count' => (int) $rows->sum('orders_count'),
            'avg_sale_per_order' => $rows->sum('orders_count') > 0
                ? round($totalSales / $rows->sum('orders_count'), 2)
                : 0.0,
            'total_commission_earned' => (float) $rows->sum('commission'),
            'debt_incurred_in_period' => (float) $this->debtRows($waiterId, $range)->sum('amount'),
            'current_outstanding_debt_balance' => $this->debtBalanceAsOf($waiterId, CarbonImmutable::now()),
        ];
    }

    /**
     * One row per waiter for the period, sorted by shortfall rate
     * descending — how weak staff are identified per the spec. Derived
     * from perShiftRows() per waiter so this always agrees with the
     * single-waiter "Shifts" view for the same range (see class docblock).
     */
    public function allWaiters(DateRange $range): Collection
    {
        $waiters = User::whereHas('roles', fn ($q) => $q->where('name', 'waiter'))->get();

        return $waiters->map(function (User $waiter) use ($range) {
            $shiftRows = $this->perShiftRows($waiter->id, $range);
            $salesHandled = (float) $shiftRows->sum('total_sales');
            $shortfall = (float) $shiftRows->sum('shortfall');

            return [
                'waiter_id' => $waiter->id,
                'waiter_name' => $waiter->name,
                'sales_handled' => $salesHandled,
                'shortfall' => $shortfall,
                'shortfall_rate_pct' => $salesHandled > 0 ? round($shortfall / $salesHandled * 100, 2) : 0.0,
                'commission_earned' => (float) $shiftRows->sum('commission'),
                'outstanding_debt' => $this->debtBalanceAsOf($waiter->id, CarbonImmutable::now()),
            ];
        })->sortByDesc('shortfall_rate_pct')->values();
    }

    /**
     * All debt incurred by this waiter up to (and including) $asOf, minus
     * all repayments recorded up to that same moment — a true point-in-
     * time balance, not an incremental running total that could drift.
     */
    private function debtBalanceAsOf(int $waiterId, CarbonImmutable $asOf): float
    {
        $debts = StaffDebt::where('user_id', $waiterId)
            ->where('created_at', '<=', $asOf)
            ->with('repayments')
            ->get();

        return (float) $debts->sum(function (StaffDebt $debt) use ($asOf) {
            $repaid = (float) $debt->repayments->where('created_at', '<=', $asOf)->sum('amount');

            return max(0.0, (float) $debt->amount - $repaid);
        });
    }
}
