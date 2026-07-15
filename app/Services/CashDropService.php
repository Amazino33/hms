<?php

namespace App\Services;

use App\Models\CashDrop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cash drops route to a shared cashier queue, not a waiter-chosen named
 * recipient — this used to require picking a specific manager, and only
 * that exact person could ever confirm it (PendingCashDrops scoped every
 * manager to just their own inbox). Now: any on-duty cashier (or a
 * supervisor, as fallback) can pick it up from the shared queue and
 * confirm — first to act wins the row-lock, no named ownership.
 */
class CashDropService
{
    private const RECEIVER_ROLES = ['cashier', 'manager', 'admin', 'super_admin'];

    /**
     * @throws \Exception
     */
    public function declare(User $waiter, float $amount, ?string $note = null): CashDrop
    {
        if ($amount <= 0) {
            throw new \Exception('Amount must be greater than zero.');
        }

        $shift = $waiter->currentShift();

        if (! $shift) {
            throw new \Exception('You must be on an active shift to drop cash.');
        }

        return CashDrop::create([
            'waiter_id' => $waiter->id,
            'received_by' => null,
            'shift_id' => $shift->id,
            'declared_amount' => $amount,
            'status' => 'pending',
            'note' => $note,
        ]);
    }

    /**
     * Any eligible role can confirm — whoever actually does becomes the
     * recorded receiver at this point, not before. If what they counted
     * differs from the waiter's declared figure, that's recorded too
     * (the cashier's count is what actually reduces the waiter's expected
     * cash — see ShiftAccountingService::confirmedDropsTotal()) rather
     * than silently overwriting the declaration.
     *
     * @throws \Exception
     */
    public function confirm(CashDrop $drop, User $confirmingUser, ?float $actualAmount = null): CashDrop
    {
        return DB::transaction(function () use ($drop, $confirmingUser, $actualAmount) {
            // Locked + re-checked inside the transaction so two near-
            // simultaneous confirm clicks (two cashiers both tapping the
            // same queue row) can't both observe 'pending' and both apply.
            $drop = CashDrop::query()->lockForUpdate()->findOrFail($drop->id);

            if (! $drop->isPending()) {
                throw new \Exception('This drop has already been confirmed.');
            }

            if (! $confirmingUser->hasRole(self::RECEIVER_ROLES)) {
                throw new \Exception('You are not able to confirm cash drops.');
            }

            $confirmedAmount = $actualAmount ?? (float) $drop->declared_amount;

            if ($confirmedAmount <= 0) {
                throw new \Exception('Confirmed amount must be greater than zero.');
            }

            $drop->update([
                'received_by' => $confirmingUser->id,
                'confirmed_amount' => $confirmedAmount,
                'status' => 'confirmed',
                'confirmed_at' => now(),
            ]);

            return $drop->fresh();
        });
    }
}
