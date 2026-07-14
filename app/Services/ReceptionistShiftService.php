<?php

namespace App\Services;

use App\Models\FolioLine;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors ShiftAccountingService's waiter pattern (declare -> pending
 * supervisor -> supervisor confirms actual counted amounts -> variance
 * frozen, shortfall becomes a StaffDebt) but is a fully separate service —
 * waiter settlement math is untouched. The one real difference: a
 * receptionist starts with a till float, so "expected cash" is the float
 * plus what they collected, not just what they collected.
 */
class ReceptionistShiftService
{
    public function startShift(User $user, float $startingFloat): Shift
    {
        if ($current = $user->currentShift()) {
            $current->update(['ended_at' => now(), 'status' => 'closed']);
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
            'status' => 'pending_supervisor',
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
     * @return StaffDebt|null the shortfall debt, if the confirmed cash fell short
     */
    public function applyShiftSettlement(Shift $shift, User $supervisor, float $confirmedCash, float $confirmedPos, ?string $notes): ?StaffDebt
    {
        return DB::transaction(function () use ($shift, $supervisor, $confirmedCash, $confirmedPos, $notes) {
            $shift = Shift::where('id', $shift->id)->lockForUpdate()->firstOrFail();

            if ($shift->status !== 'pending_supervisor') {
                throw new \Exception('This shift is not awaiting supervisor review.');
            }

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

            $debt = null;

            if ($variance < -0.01) {
                $debt = StaffDebt::create([
                    'user_id' => $shift->user_id,
                    'shift_id' => $shift->id,
                    'amount' => abs($variance),
                    'reason' => 'reception_shortfall',
                    'status' => 'open',
                    'created_by' => $supervisor->id,
                ]);
            }

            return $debt;
        });
    }
}
