<?php

namespace App\Services;

use App\Models\CashDrop;
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
     * Cash the waiter is still expected to physically hand over at
     * settlement — cash-method payments collected during the shift, minus
     * any cash drops already confirmed by whoever received them mid-shift.
     * A pending (unconfirmed) drop does NOT reduce this — only a confirmed
     * one, since the waiter's own declaration alone isn't proof the cash
     * actually changed hands. Cancelled orders never contribute.
     *
     * 'split' payments don't decompose cash/pos at the payment-row level
     * (a single row holds the combined amount), so for those we fall back
     * to the parent order's own paid_cash snapshot, which OrderSplitter
     * already computes proportionally per destination order.
     */
    public function expectedCashRemittance(Shift $shift): float
    {
        $cashCollected = (float) $this->shiftPayments($shift)->sum(function (OrderPayment $payment) {
            return match ($payment->method) {
                'cash' => (float) $payment->amount,
                'split' => (float) ($payment->order->paid_cash ?? 0),
                default => 0.0,
            };
        });

        return max(0, $cashCollected - $this->confirmedDropsTotal($shift));
    }

    /**
     * Sum of cash drops confirmed by their named receiver during this
     * shift — pending/unconfirmed declarations are worth nothing here.
     */
    public function confirmedDropsTotal(Shift $shift): float
    {
        return (float) CashDrop::where('shift_id', $shift->id)
            ->where('status', 'confirmed')
            ->sum('confirmed_amount');
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
     * The physical POS terminal's expected batch total specifically —
     * unlike expectedPosTotal() (which also folds in transfers, for
     * general reporting), this deliberately excludes transfer payments,
     * since those never touch the machine and are reconciled one-by-one
     * in the cashier's transfer queue instead.
     */
    public function expectedPosMachineTotal(Shift $shift): float
    {
        return (float) $this->shiftPayments($shift)->sum(function (OrderPayment $payment) {
            return match ($payment->method) {
                'pos' => (float) $payment->amount,
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

    /**
     * Return tickets this waiter opened during this shift that no bartender/
     * chef has confirmed or rejected yet. Left unresolved, these are exactly
     * the kind of thing the whole confirmed-returns feature exists to catch
     * — a waiter can't just walk away from a claimed return that never
     * actually got confirmed.
     */
    public function pendingReturns(Shift $shift): Collection
    {
        return Order::where('shift_id', $shift->id)
            ->where('is_return', true)
            ->where('status', 'pending')
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
