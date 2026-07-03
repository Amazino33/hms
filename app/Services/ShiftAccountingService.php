<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\User;
use Illuminate\Support\Collection;

class ShiftAccountingService
{
    /**
     * A supervisor converts a specific unpaid order into a tracked staff
     * debt (e.g. a guest walked out without paying). This both records the
     * debt and resolves the order so it stops blocking shift close.
     */
    public function convertOrderToDebt(Order $order, User $supervisor, ?string $notes = null): StaffDebt
    {
        $remaining = max(0, (float) $order->total_amount - (float) $order->amount_paid);

        $debt = StaffDebt::create([
            'user_id' => $order->user_id,
            'shift_id' => $order->shift_id,
            'order_id' => $order->id,
            'amount' => $remaining,
            'reason' => 'unpaid_order_conversion',
            'status' => 'open',
            'created_by' => $supervisor->id,
            'notes' => $notes,
        ]);

        $order->update([
            'amount_paid' => $order->total_amount,
            'status' => 'paid',
            'processed_by_user_id' => $supervisor->id,
        ]);

        return $debt;
    }

    /**
     * Supervisor approves a shift close: freezes server-computed expected
     * totals against what the supervisor physically confirmed, and — only
     * on a shortfall — opens a staff debt for the difference. A surplus is
     * recorded but never generates a (negative) debt.
     */
    public function applyShiftSettlement(
        Shift $shift,
        User $supervisor,
        float $confirmedCash,
        float $confirmedPos,
        ?string $notes = null,
    ): ?StaffDebt {
        $expectedCash = $this->expectedCashRemittance($shift);
        $expectedPos = $this->expectedPosTotal($shift);
        $variance = round($confirmedCash - $expectedCash, 2);

        $shift->update([
            'supervisor_confirmed_cash' => $confirmedCash,
            'supervisor_confirmed_pos' => $confirmedPos,
            'expected_cash' => $expectedCash,
            'expected_pos' => $expectedPos,
            'cash_variance' => $variance,
            'surplus_amount' => $variance > 0 ? $variance : 0,
            'settlement_notes' => $notes,
            'settled_at' => now(),
            'status' => 'closed',
        ]);

        if ($variance < -0.01) {
            return StaffDebt::create([
                'user_id' => $shift->user_id,
                'shift_id' => $shift->id,
                'amount' => abs($variance),
                'reason' => 'shift_shortfall',
                'status' => 'open',
                'created_by' => $supervisor->id,
            ]);
        }

        return null;
    }

    /**
     * Cash actually collected during this shift — the amount the waiter is
     * physically holding and must remit. Cancelled orders never contribute.
     *
     * 'split' payments don't decompose cash/pos at the payment-row level
     * (a single row holds the combined amount), so for those we fall back
     * to the parent order's own paid_cash snapshot, which OrderSplitter
     * already computes proportionally per destination order.
     */
    public function expectedCashRemittance(Shift $shift): float
    {
        return (float) $this->shiftPayments($shift)->sum(function (OrderPayment $payment) {
            return match ($payment->method) {
                'cash' => (float) $payment->amount,
                'split' => (float) ($payment->order->paid_cash ?? 0),
                default => 0.0,
            };
        });
    }

    /**
     * POS/transfer total collected during this shift — shown separately for
     * slip reconciliation, never counted as cash the waiter must hand over.
     */
    public function expectedPosTotal(Shift $shift): float
    {
        return (float) $this->shiftPayments($shift)->sum(function (OrderPayment $payment) {
            return match ($payment->method) {
                'pos', 'transfer' => (float) $payment->amount,
                'split' => (float) ($payment->order->paid_pos ?? 0),
                default => 0.0,
            };
        });
    }

    /**
     * Orders created during this shift that still carry an outstanding
     * balance — i.e. money a guest owes that hasn't been collected yet.
     * Cancelled/returned orders are excluded; a fully-paid order can never
     * appear here since amount_paid >= total_amount by definition.
     */
    public function outstandingOrders(Shift $shift): Collection
    {
        return Order::where('shift_id', $shift->id)
            ->whereNotIn('status', ['paid', 'cancelled', 'returned'])
            ->whereColumn('amount_paid', '<', 'total_amount')
            ->with('items')
            ->get();
    }

    public function outstandingBalance(Shift $shift): float
    {
        return (float) $this->outstandingOrders($shift)->sum(
            fn (Order $order) => max(0, $order->total_amount - $order->amount_paid)
        );
    }

    /**
     * Payments recorded against this specific shift (i.e. collected while
     * this waiter/cashier was clocked in), excluding cancelled orders.
     */
    protected function shiftPayments(Shift $shift): Collection
    {
        return OrderPayment::where('shift_id', $shift->id)
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->with('order')
            ->get();
    }
}
