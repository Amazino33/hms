<?php

namespace App\Services;

use App\Models\FolioLine;
use App\Models\Shift;
use App\Models\User;

/**
 * Start/declare-end only. Confirmation (cash blind-count, POS-machine
 * check, transfer auto-complete, StaffDebt on shortfall) is now
 * CashierSettlementService's job for every shift type, waiter and
 * receptionist alike — the old single-step applyShiftSettlement() here
 * (and its ShiftAccountingService twin) is gone; nothing closes a
 * settlement anymore except the cashier's own channel-by-channel
 * confirmation. Only expectedCashRemittance()/expectedPosTotal() remain,
 * since those stay genuinely different per type (a receptionist's
 * expected cash includes her starting till float and is sourced from
 * FolioLine, not OrderPayment).
 */
class ReceptionistShiftService
{
    public function startShift(User $user, float $startingFloat): Shift
    {
        if ($current = $user->currentShift()) {
            if ($current->type === 'receptionist') {
                return $current;
            }

            throw new \Exception("You have an active {$current->type} shift — end it before starting a receptionist shift.");
        }

        if (Shift::hasUnsettledFor($user->id) && ! SettingsService::getBool('allow_shift_start_with_unsettled')) {
            throw new \Exception('Your last settlement is awaiting cashier confirmation and must be resolved before you can start a new shift.');
        }

        return $user->shifts()->create([
            'type' => 'receptionist',
            'starting_float' => $startingFloat,
            'started_at' => now(),
            'status' => 'active',
        ]);
    }

    public function declareEnd(Shift $shift, float $declaredCash, float $declaredPos): Shift
    {
        if ($shift->type !== 'receptionist') {
            throw new \Exception('This is not a receptionist shift.');
        }

        if ($shift->status !== 'active') {
            throw new \Exception('This shift is not active.');
        }

        $shift->update([
            'declared_cash' => $declaredCash,
            'declared_pos' => $declaredPos,
            'ended_at' => now(),
            'status' => 'awaiting_cashier',
        ]);

        return $shift->fresh();
    }

    public function expectedCashRemittance(Shift $shift): float
    {
        $collected = abs((float) FolioLine::where('shift_id', $shift->id)
            ->where('type', 'payment')
            ->where('payment_method', 'cash')
            ->sum('amount'));

        return (float) ($shift->starting_float ?? 0) + $collected;
    }

    public function expectedPosTotal(Shift $shift): float
    {
        return abs((float) FolioLine::where('shift_id', $shift->id)
            ->where('type', 'payment')
            ->whereIn('payment_method', ['transfer', 'pos_terminal'])
            ->sum('amount'));
    }

    /**
     * Physical POS terminal batch only, excluding transfers — those are
     * reconciled through the existing hotel folio TransferVerification
     * page (FolioLine.verified), not the cashier's per-channel POS-machine
     * confirmation.
     */
    public function expectedPosMachineTotal(Shift $shift): float
    {
        return abs((float) FolioLine::where('shift_id', $shift->id)
            ->where('type', 'payment')
            ->where('payment_method', 'pos_terminal')
            ->sum('amount'));
    }
}
