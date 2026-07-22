<?php

namespace App\Services;

use App\Models\OrderPayment;
use App\Models\Shift;
use App\Models\ShiftChannelConfirmation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Supervisor rulings on the two kinds of flag: a disputed transfer
 * payment, or a POS-machine total mismatch. Both share the same three
 * outcomes (late_verify / charge / void) and both, once ruled, may be
 * exactly what a settlement was waiting on — each ruling re-attempts
 * CashierSettlementService::finalizeIfComplete() on its owning shift.
 */
class SettlementFlagRulingService
{
    private const RULINGS = ['late_verify', 'charge', 'void'];

    public function ruleTransfer(OrderPayment $payment, string $ruling, string $note, int $supervisorId): OrderPayment
    {
        $this->assertValidRuling($ruling);

        return DB::transaction(function () use ($payment, $ruling, $note, $supervisorId) {
            $payment = OrderPayment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if (! $payment->flagged) {
                throw new \Exception('This transfer payment is not flagged.');
            }

            if ($payment->ruling !== null) {
                throw new \Exception('This flag has already been ruled on.');
            }

            $payment->update([
                'ruling' => $ruling,
                'ruling_note' => $note,
                'ruled_by' => $supervisorId,
                'ruled_at' => now(),
                'verified' => $ruling === 'late_verify' ? true : $payment->verified,
                'verified_by' => $ruling === 'late_verify' ? $supervisorId : $payment->verified_by,
                'verified_at' => $ruling === 'late_verify' ? now() : $payment->verified_at,
            ]);

            activity('order_payment')
                ->performedOn($payment)
                ->causedBy(User::find($supervisorId))
                ->withProperties(['ruling' => $ruling, 'note' => $note])
                ->log('Transfer flag ruled: '.$ruling);

            if ($payment->shift_id) {
                $shift = Shift::find($payment->shift_id);
                if ($shift && $shift->status === 'awaiting_cashier') {
                    (new CashierSettlementService)->finalizeIfComplete($shift, $supervisorId);
                }
            }

            return $payment->fresh();
        });
    }

    public function rulePosMachine(Shift $shift, string $ruling, string $note, int $supervisorId): Shift
    {
        $this->assertValidRuling($ruling);

        return DB::transaction(function () use ($shift, $ruling, $note, $supervisorId) {
            $shift = Shift::where('id', $shift->id)->lockForUpdate()->firstOrFail();

            if (! $shift->pos_flagged) {
                throw new \Exception('This settlement has no open POS-machine dispute.');
            }

            if ($shift->pos_ruling !== null) {
                throw new \Exception('This POS-machine dispute has already been ruled on.');
            }

            $shift->update([
                'pos_ruling' => $ruling,
                'pos_ruling_note' => $note,
                'pos_ruled_by' => $supervisorId,
                'pos_ruled_at' => now(),
                'pos_flagged' => false,
            ]);

            activity('shift')
                ->performedOn($shift)
                ->causedBy(User::find($supervisorId))
                ->withProperties(['ruling' => $ruling, 'note' => $note])
                ->log('POS-machine dispute ruled: '.$ruling);

            return (new CashierSettlementService)->finalizeIfComplete($shift->fresh(), $supervisorId);
        });
    }

    /**
     * The per-destination equivalent of rulePosMachine() above, for a
     * waiter shift using the bar/kitchen split settlement — a flagged
     * bar-POS or kitchen-POS mismatch, ruled independently of the other
     * destination's own channel confirmations.
     */
    public function ruleChannelConfirmation(ShiftChannelConfirmation $confirmation, string $ruling, string $note, int $supervisorId): ShiftChannelConfirmation
    {
        $this->assertValidRuling($ruling);

        return DB::transaction(function () use ($confirmation, $ruling, $note, $supervisorId) {
            $confirmation = ShiftChannelConfirmation::where('id', $confirmation->id)->lockForUpdate()->firstOrFail();

            if (! $confirmation->flagged) {
                throw new \Exception('This channel confirmation has no open dispute.');
            }

            if ($confirmation->ruling !== null) {
                throw new \Exception('This dispute has already been ruled on.');
            }

            $confirmation->update([
                'ruling' => $ruling,
                'ruling_note' => $note,
                'ruled_by' => $supervisorId,
                'ruled_at' => now(),
                'flagged' => false,
            ]);

            activity('shift_channel_confirmation')
                ->performedOn($confirmation)
                ->causedBy(User::find($supervisorId))
                ->withProperties(['ruling' => $ruling, 'note' => $note])
                ->log('Channel confirmation dispute ruled: '.$ruling);

            $shift = Shift::find($confirmation->shift_id);
            if ($shift && $shift->status === 'awaiting_cashier') {
                (new CashierSettlementService)->finalizeIfComplete($shift, $supervisorId);
            }

            return $confirmation->fresh();
        });
    }

    private function assertValidRuling(string $ruling): void
    {
        if (! in_array($ruling, self::RULINGS, true)) {
            throw new \Exception('Invalid ruling.');
        }
    }
}
