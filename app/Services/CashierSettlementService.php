<?php

namespace App\Services;

use App\Models\FolioLine;
use App\Models\OrderPayment;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The cashier's channel-by-channel settlement confirmation — replaces the
 * old single-step "supervisor confirms and closes" action for both waiter
 * and receptionist shifts. A settlement reaches 'confirmed' only once
 * every channel present has been confirmed and no flag is open;
 * StaffDebt is created at that moment from the cashier-counted cash, not
 * the staff member's own declaration (which is never read here at all —
 * blind by construction, not by hiding a value the code still has).
 */
class CashierSettlementService
{
    public function expectedCash(Shift $shift): float
    {
        return $shift->type === 'receptionist'
            ? (new ReceptionistShiftService())->expectedCashRemittance($shift)
            : (new ShiftAccountingService())->expectedCashRemittance($shift);
    }

    public function expectedPosMachine(Shift $shift): float
    {
        return $shift->type === 'receptionist'
            ? (new ReceptionistShiftService())->expectedPosMachineTotal($shift)
            : (new ShiftAccountingService())->expectedPosMachineTotal($shift);
    }

    /**
     * The cashier's own physically-counted cash figure. Never receives or
     * reads $shift->declared_cash — the blind guarantee holds because
     * this method has no access to it, not because a caller remembered to
     * hide it.
     */
    public function confirmCash(Shift $shift, float $cashierCountedCash, int $confirmingUserId): Shift
    {
        return DB::transaction(function () use ($shift, $cashierCountedCash, $confirmingUserId) {
            $shift = Shift::where('id', $shift->id)->lockForUpdate()->firstOrFail();
            $this->assertAwaitingCashier($shift);

            if ($shift->cash_confirmed_at) {
                throw new \Exception('Cash has already been confirmed for this settlement.');
            }

            $shift->update([
                'cashier_counted_cash' => $cashierCountedCash,
                'cash_confirmed_by' => $confirmingUserId,
                'cash_confirmed_at' => now(),
            ]);

            return $this->finalizeIfComplete($shift->fresh(), $confirmingUserId);
        });
    }

    /**
     * A mismatch never auto-creates debt — it flags for supervisor ruling,
     * since a POS-machine dispute isn't automatically the staff member's
     * fault (could be a batch timing issue, a machine fault, etc.).
     */
    public function confirmPos(Shift $shift, float $machineAmount, int $confirmingUserId): Shift
    {
        return DB::transaction(function () use ($shift, $machineAmount, $confirmingUserId) {
            $shift = Shift::where('id', $shift->id)->lockForUpdate()->firstOrFail();
            $this->assertAwaitingCashier($shift);

            if ($shift->pos_confirmed_at) {
                throw new \Exception('The POS machine total has already been confirmed for this settlement.');
            }

            $expected = $this->expectedPosMachine($shift);
            $matches = abs($machineAmount - $expected) < 0.01;

            $shift->update([
                'pos_machine_confirmed_amount' => $machineAmount,
                'pos_confirmed_by' => $confirmingUserId,
                'pos_confirmed_at' => now(),
                'pos_flagged' => ! $matches,
            ]);

            return $this->finalizeIfComplete($shift->fresh(), $confirmingUserId);
        });
    }

    /**
     * True the instant there's nothing left to verify — a shift with no
     * transfer payments at all trivially has a complete transfer channel.
     * Waiter shifts check OrderPayment (the new cashier queue, built for
     * this feature); receptionist shifts check FolioLine — the hotel
     * folio's own, already-existing TransferVerification page and its
     * verified flag, reused as-is rather than duplicated.
     */
    public function transferChannelComplete(Shift $shift): bool
    {
        if ($shift->type === 'receptionist') {
            return ! FolioLine::where('shift_id', $shift->id)
                ->where('type', 'payment')
                ->where('payment_method', 'transfer')
                ->where('verified', false)
                ->exists();
        }

        return ! OrderPayment::where('shift_id', $shift->id)
            ->where('method', 'transfer')
            ->where('verified', false)
            ->whereNull('ruling')
            ->exists();
    }

    /**
     * Call after any channel-affecting action (a channel confirm, or a
     * transfer verify/flag-ruling elsewhere) — closes the settlement the
     * moment every channel is done and no flag is open, otherwise a no-op.
     */
    public function finalizeIfComplete(Shift $shift, int $actingUserId): Shift
    {
        if ($shift->status !== 'awaiting_cashier') {
            return $shift;
        }

        if (! $shift->cash_confirmed_at || ! $shift->pos_confirmed_at) {
            return $shift;
        }

        if (! $this->transferChannelComplete($shift)) {
            return $shift;
        }

        if ($shift->hasOpenFlag()) {
            return $shift;
        }

        $expectedCash = $this->expectedCash($shift);
        $variance = round((float) $shift->cashier_counted_cash - $expectedCash, 2);
        $cashShortfall = $variance < -0.01 ? abs($variance) : 0.0;

        $chargedAmount = $this->chargedFlagTotal($shift);
        $totalDebt = round($cashShortfall + $chargedAmount, 2);

        $shift->update([
            'expected_cash' => $expectedCash,
            'expected_pos' => $this->expectedPosMachine($shift),
            'cash_variance' => $variance,
            'surplus_amount' => $variance > 0 ? $variance : 0,
            'settled_at' => now(),
            'status' => 'confirmed',
        ]);

        if ($totalDebt > 0.01) {
            $notes = $cashShortfall > 0 ? "Cash shortfall: ₦" . number_format($cashShortfall, 2) : null;
            if ($chargedAmount > 0) {
                $notes = trim(($notes ? $notes . '. ' : '') . "Charged flags (supervisor-ruled): ₦" . number_format($chargedAmount, 2));
            }

            StaffDebt::create([
                'user_id' => $shift->user_id,
                'shift_id' => $shift->id,
                'amount' => $totalDebt,
                'reason' => $shift->type === 'receptionist' ? 'reception_shortfall' : 'shift_shortfall',
                'status' => 'open',
                'created_by' => $actingUserId,
                'notes' => $notes,
            ]);
        }

        activity('shift')
            ->performedOn($shift)
            ->causedBy(User::find($actingUserId))
            ->withProperties(['variance' => $variance, 'charged_flags' => $chargedAmount])
            ->log('Settlement confirmed');

        return $shift->fresh();
    }

    /**
     * The sum of everything a supervisor ruled 'charge' against this
     * settlement — a POS-machine dispute charged to the staff member, plus
     * (waiter shifts only; receptionist transfers resolve through the
     * existing hotel folio reject flow, which never uses this ruling)
     * any transfer payments charged individually.
     */
    private function chargedFlagTotal(Shift $shift): float
    {
        $total = $shift->pos_ruling === 'charge'
            ? abs((float) $shift->pos_machine_confirmed_amount - $this->expectedPosMachine($shift))
            : 0.0;

        if ($shift->type !== 'receptionist') {
            $total += (float) OrderPayment::where('shift_id', $shift->id)
                ->where('ruling', 'charge')
                ->sum('amount');
        }

        return $total;
    }

    private function assertAwaitingCashier(Shift $shift): void
    {
        if ($shift->status !== 'awaiting_cashier') {
            throw new \Exception('This settlement is not awaiting cashier confirmation.');
        }
    }
}
