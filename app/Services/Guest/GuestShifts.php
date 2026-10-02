<?php

namespace App\Services\Guest;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Shift questions the guest flow asks (Phase 3), answered with the same
 * "active, non-stale" rule order creation enforces
 * (OrderSplitter::assertShiftsActive) — a shift older than
 * Shift::STALE_AFTER_HOURS counts as abandoned, never as on duty.
 */
class GuestShifts
{
    public static function activeWaiterShift(?User $user): ?Shift
    {
        if (! $user) {
            return null;
        }

        return Shift::query()->where('user_id', $user->id)->activeNonStale('waiter')->latest('started_at')->first();
    }

    /** @return Collection<int, Shift> with their users, oldest first */
    public static function activeBartenderShifts(): Collection
    {
        return Shift::query()->activeNonStale('bartender')->with('user')->orderBy('started_at')->get();
    }

    public static function firstName(?User $user): string
    {
        return $user ? (string) str($user->name)->before(' ') : 'Another waiter';
    }
}
