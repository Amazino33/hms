<?php

namespace App\Services\Guest;

use App\Models\GuestRequest;
use App\Models\GuestTableSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Closing a guest sitting (Phase 4, D20) — the only writer of a sitting's
 * closed_at. Closing is a manual waiter action, never automatic after
 * payment (bars often pay per round), and is refused while anything is
 * still unpaid or waiting. guest:expire-stale closes idle sittings through
 * closeStale() here too, under the same "nothing unpaid" rule (D14).
 */
class TableCloseService
{
    /**
     * @throws \Exception listing what blocks the close
     */
    public function close(GuestTableSession $session, User $waiter): GuestTableSession
    {
        return DB::transaction(function () use ($session, $waiter) {
            $session = GuestTableSession::with(['assignedWaiter', 'table'])->lockForUpdate()->find($session->id);

            if (! $session?->isOpen()) {
                throw new \Exception('This guest table is already closed.');
            }

            TableMoveService::assertMayManage($session, $waiter, 'close');

            $blockers = self::blockers($session);

            if ($blockers !== []) {
                throw new \Exception('Can\'t close '.$session->getRelationValue('table')?->name.' yet: '.implode('; ', $blockers).'.');
            }

            $session->update([
                'closed_at' => now(),
                'closed_by_user_id' => $waiter->id,
                'close_reason' => GuestTableSession::CLOSE_BY_WAITER,
            ]);

            return $session;
        });
    }

    /**
     * What stops a sitting closing, in words for the waiter.
     *
     * @return list<string>
     */
    public static function blockers(GuestTableSession $session): array
    {
        $blockers = [];
        $unpaid = GuestBillService::unpaidOrders($session);

        if ($unpaid->isNotEmpty()) {
            $owed = $unpaid->sum(fn ($o) => max(0, (float) $o->total_amount - (float) $o->amount_paid));
            $blockers[] = '₦'.number_format($owed).' unpaid ('.$unpaid->count().' '.str('order')->plural($unpaid->count()).')';
        }

        foreach (GuestBillService::waitingLines($session)->groupBy('status') as $status => $lines) {
            $where = match ($status) {
                'pending' => 'waiting for a waiter',
                'at_bar' => 'at the bar',
                default => 'waiting for a waiter to take them',
            };
            $blockers[] = $lines->map(fn ($l) => $l->finalQuantity().'× '.$l->name_snapshot)->join(', ').' '.$where;
        }

        return $blockers;
    }

    /**
     * guest:expire-stale (D14, D20): sittings idle for 3 hours with no
     * pending request, no line still waiting and NOTHING UNPAID close.
     */
    public function closeStale(): int
    {
        $cutoff = now()->subHours(GuestRequestService::STALE_AFTER_HOURS);
        $closed = 0;

        GuestTableSession::open()
            ->where('last_activity_at', '<=', $cutoff)
            ->whereDoesntHave('requests', fn ($q) => $q->where('status', GuestRequest::STATUS_PENDING))
            ->each(function (GuestTableSession $session) use (&$closed) {
                if (self::blockers($session) !== []) {
                    return;
                }

                $session->update([
                    'closed_at' => now(),
                    'close_reason' => GuestTableSession::CLOSE_AUTO_STALE,
                ]);
                $closed++;
            });

        return $closed;
    }
}
