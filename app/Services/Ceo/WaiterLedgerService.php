<?php

namespace App\Services\Ceo;

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
 */
class WaiterLedgerService
{
    public function __construct(private readonly RevenueReportService $revenue = new RevenueReportService())
    {
    }

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

            $paymentsByMethod = OrderPayment::where('shift_id', $shift->id)->get()->groupBy('method');
            $shortfall = (float) StaffDebt::where('shift_id', $shift->id)->where('reason', 'shift_shortfall')->sum('amount');

            return [
                'shift_id' => $shift->id,
                'date' => $shift->ended_at,
                'orders_count' => $orderIds->count(),
                'total_sales' => $totalSales,
                'cash_declared' => (float) ($paymentsByMethod->get('cash')?->sum('amount') ?? 0),
                'pos_total' => (float) ($paymentsByMethod->get('pos')?->sum('amount') ?? 0),
                'transfer_total' => (float) ($paymentsByMethod->get('transfer')?->sum('amount') ?? 0),
                'shortfall' => $shortfall,
                'shortfall_rate_pct' => $totalSales > 0 ? round($shortfall / $totalSales * 100, 2) : 0.0,
                'running_debt_balance' => $this->debtBalanceAsOf($waiterId, $shift->ended_at),
            ];
        });
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
            'current_outstanding_debt_balance' => $this->debtBalanceAsOf($waiterId, CarbonImmutable::now()),
        ];
    }

    /**
     * One row per waiter for the period, sorted by shortfall rate
     * descending — how weak staff are identified per the spec.
     */
    public function allWaiters(DateRange $range): Collection
    {
        $waiters = User::whereHas('roles', fn ($q) => $q->where('name', 'waiter'))->get();

        return $waiters->map(function (User $waiter) use ($range) {
            $salesHandled = $this->revenue->lineItems($range, ['sold_by' => $waiter->id])->sum('revenue');
            $shortfall = (float) StaffDebt::where('user_id', $waiter->id)
                ->where('reason', 'shift_shortfall')
                ->whereBetween('created_at', [$range->startBoundary(), $range->endBoundary()])
                ->sum('amount');

            return [
                'waiter_id' => $waiter->id,
                'waiter_name' => $waiter->name,
                'sales_handled' => (float) $salesHandled,
                'shortfall' => $shortfall,
                'shortfall_rate_pct' => $salesHandled > 0 ? round($shortfall / $salesHandled * 100, 2) : 0.0,
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
