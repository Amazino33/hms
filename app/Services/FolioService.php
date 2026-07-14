<?php

namespace App\Services;

use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every folio line is immutable once created — corrections are always a
 * new appended line, never an edit (see Folio/FolioLine docblocks). A
 * transfer payment posts unverified by default; cash/POS-terminal
 * payments are self-evident (the receptionist physically handled them)
 * and post verified immediately. Verifying/rejecting a transfer never
 * touches the original line — rejection posts a reversal charge instead.
 */
class FolioService
{
    public function postIncidental(Folio $folio, string $description, float $amount, int $userId): FolioLine
    {
        $this->assertNotSealed($folio);

        if ($amount <= 0) {
            throw new \Exception('Incidental charge amount must be greater than zero.');
        }

        return FolioLine::create([
            'folio_id' => $folio->id,
            'type' => 'incidental',
            'amount' => $amount,
            'description' => $description,
            'created_by' => $userId,
        ]);
    }

    public function recordPayment(Folio $folio, float $amount, string $method, ?string $reference, int $userId): FolioLine
    {
        $this->assertNotSealed($folio);

        if ($amount <= 0) {
            throw new \Exception('Payment amount must be greater than zero.');
        }

        if (! in_array($method, ['cash', 'transfer', 'pos_terminal'], true)) {
            throw new \Exception('Invalid payment method.');
        }

        return FolioLine::create([
            'folio_id' => $folio->id,
            'type' => 'payment',
            'amount' => -$amount,
            'description' => 'Payment received (' . str_replace('_', ' ', $method) . ')',
            'created_by' => $userId,
            // Attributed to whoever is on shift right now (any type — a
            // manager covering the desk still needs this counted toward
            // their own shift) so ReceptionistShiftService can reconcile
            // cash collected during a shift. Null if nobody's clocked in;
            // that's not blocked here, just unattributed.
            'shift_id' => User::find($userId)?->currentShift()?->id,
            'payment_method' => $method,
            'reference' => $reference,
            'verified' => $method !== 'transfer',
        ]);
    }

    public function verifyTransfer(FolioLine $line, int $managerId): FolioLine
    {
        return DB::transaction(function () use ($line, $managerId) {
            $line = FolioLine::where('id', $line->id)->lockForUpdate()->firstOrFail();

            $this->assertUnverifiedTransfer($line);

            $line->update([
                'verified' => true,
                'verified_by' => $managerId,
                'verified_at' => now(),
            ]);

            activity('folio_line')
                ->performedOn($line)
                ->causedBy(User::find($managerId))
                ->log('Transfer payment verified');

            return $line->fresh();
        });
    }

    public function rejectTransfer(FolioLine $line, string $reason, int $managerId): FolioLine
    {
        return DB::transaction(function () use ($line, $reason, $managerId) {
            $line = FolioLine::where('id', $line->id)->lockForUpdate()->firstOrFail();

            $this->assertUnverifiedTransfer($line);

            FolioLine::create([
                'folio_id' => $line->folio_id,
                'type' => 'adjustment',
                'amount' => abs((float) $line->amount),
                'description' => 'Transfer payment not received — reversing: ' . $reason,
                'created_by' => $managerId,
            ]);

            // Marked verified=true too — not because the transfer was good,
            // but because a manager has now resolved it either way, which
            // is what clears it from the pending-verification queue. The
            // reference records which way it went; the reversal line above
            // is what actually neutralizes it in the balance.
            $line->update([
                'verified' => true,
                'verified_by' => $managerId,
                'verified_at' => now(),
                'reference' => 'Rejected: ' . $reason,
            ]);

            activity('folio_line')
                ->performedOn($line)
                ->causedBy(User::find($managerId))
                ->withProperties(['reason' => $reason])
                ->log('Transfer payment rejected');

            return $line->fresh();
        });
    }

    /**
     * New charges/payments are blocked once the guest has checked out —
     * that's what "sealed" means. Verifying/rejecting an existing transfer
     * line is deliberately NOT gated here (see rejectTransfer's caller,
     * BookingService::checkOut()'s docblock) — that's a manager resolving
     * something that already happened before checkout, not new activity.
     */
    private function assertNotSealed(Folio $folio): void
    {
        if ($folio->booking?->status === 'checked_out') {
            throw new \Exception('This folio is sealed — the guest has already checked out.');
        }
    }

    private function assertUnverifiedTransfer(FolioLine $line): void
    {
        if ($line->type !== 'payment' || $line->payment_method !== 'transfer') {
            throw new \Exception('Only a transfer payment line can be verified or rejected.');
        }

        if ($line->verified) {
            throw new \Exception('This transfer payment has already been verified.');
        }
    }
}
