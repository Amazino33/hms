<?php

namespace App\Services;

use App\Models\CashDrop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CashDropService
{
    private const RECEIVER_ROLES = ['manager', 'admin', 'super_admin'];

    /**
     * Waiter hands cash to a specific named person and declares the amount.
     * Nothing about the waiter's expected remittance changes yet — only a
     * confirmation from that exact person does that.
     *
     * @throws \Exception
     */
    public function declare(User $waiter, User $receivedBy, float $amount, ?string $note = null): CashDrop
    {
        if ($amount <= 0) {
            throw new \Exception('Amount must be greater than zero.');
        }

        if ($receivedBy->id === $waiter->id) {
            throw new \Exception('You cannot declare a cash drop to yourself.');
        }

        if (!$receivedBy->hasRole(self::RECEIVER_ROLES)) {
            throw new \Exception('The selected person is not able to receive cash drops.');
        }

        $shift = $waiter->currentShift();

        if (!$shift) {
            throw new \Exception('You must be on an active shift to drop cash.');
        }

        return CashDrop::create([
            'waiter_id' => $waiter->id,
            'received_by' => $receivedBy->id,
            'shift_id' => $shift->id,
            'declared_amount' => $amount,
            'status' => 'pending',
            'note' => $note,
        ]);
    }

    /**
     * Only the exact person the waiter named can confirm — not just any
     * manager. If what they actually counted differs from the waiter's
     * declared figure, that gets recorded too rather than silently
     * overwriting the declaration.
     *
     * @throws \Exception
     */
    public function confirm(CashDrop $drop, User $confirmingUser, ?float $actualAmount = null): CashDrop
    {
        return DB::transaction(function () use ($drop, $confirmingUser, $actualAmount) {
            // Locked + re-checked inside the transaction so two near-
            // simultaneous confirm clicks can't both observe 'pending' and
            // both apply — the second waits for the lock, then sees
            // 'confirmed' and is rejected instead of double-confirming.
            $drop = CashDrop::query()->lockForUpdate()->findOrFail($drop->id);

            if (!$drop->isPending()) {
                throw new \Exception('This drop has already been confirmed.');
            }

            if ($drop->received_by !== $confirmingUser->id) {
                throw new \Exception('Only the person this drop was made to can confirm it.');
            }

            // Re-check the role at confirmation time too, not just at
            // declaration — a demotion between the two moments must not
            // leave a stale "still a manager" assumption in place.
            if (!$confirmingUser->hasRole(self::RECEIVER_ROLES)) {
                throw new \Exception('You are no longer able to confirm cash drops.');
            }

            $confirmedAmount = $actualAmount ?? (float) $drop->declared_amount;

            if ($confirmedAmount <= 0) {
                throw new \Exception('Confirmed amount must be greater than zero.');
            }

            $drop->update([
                'confirmed_amount' => $confirmedAmount,
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            return $drop->fresh();
        });
    }
}
