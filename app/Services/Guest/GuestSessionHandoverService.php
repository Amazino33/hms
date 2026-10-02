<?php

namespace App\Services\Guest;

use App\Models\GuestRequest;
use App\Models\GuestSessionHandover;
use App\Models\GuestTableSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * D3: a waiter can't end their shift while their tables still have guest
 * drinks waiting at the bar. Handing the table over moves the sitting's
 * assignment — and with it every drink still waiting — to another waiter
 * on an active shift, who confirms with their own PIN. Orders already
 * created stay on the original waiter's ledger.
 */
class GuestSessionHandoverService
{
    /**
     * @throws \Exception
     */
    public function handover(GuestTableSession $session, User $from, User $to): GuestSessionHandover
    {
        if ($from->id === $to->id) {
            throw new \Exception('Choose a different waiter to hand the table to.');
        }

        $toShift = GuestShifts::activeWaiterShift($to);

        if (! $toShift) {
            throw new \Exception("{$to->name} isn't on an active waiter shift.");
        }

        return DB::transaction(function () use ($session, $from, $to, $toShift) {
            $session = GuestTableSession::lockForUpdate()->findOrFail($session->id);

            if ($session->assigned_waiter_user_id !== $from->id) {
                throw new \Exception('That table isn\'t yours to hand over any more.');
            }

            $requestIds = GuestRequest::where('guest_table_session_id', $session->id)
                ->whereHas('items', fn ($q) => $q->whereIn('status', ['at_bar', 'needs_waiter']))
                ->pluck('id')
                ->all();

            $session->update(['assigned_waiter_user_id' => $to->id, 'assigned_shift_id' => $toShift->id, 'last_activity_at' => now()]);

            return GuestSessionHandover::create([
                'guest_table_session_id' => $session->id,
                'from_user_id' => $from->id,
                'to_user_id' => $to->id,
                'request_ids' => $requestIds,
            ]);
        });
    }

    /**
     * The open sittings that would stop this waiter ending their shift: the
     * ones assigned to them with drinks still waiting at the bar.
     *
     * @return \Illuminate\Support\Collection<int, GuestTableSession>
     */
    public static function blockingSessions(User $waiter)
    {
        return GuestTableSession::open()
            ->where('assigned_waiter_user_id', $waiter->id)
            ->whereHas('requests.items', fn ($q) => $q->where('status', 'at_bar'))
            ->with('table')
            ->get();
    }
}
