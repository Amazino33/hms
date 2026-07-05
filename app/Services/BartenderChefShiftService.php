<?php

namespace App\Services;

use App\Models\CountSession;
use App\Models\Shift;
use App\Models\User;

class BartenderChefShiftService
{
    private const TYPE_FOR_HANDOVER = [
        'bar_handover' => 'bartender',
        'kitchen_handover' => 'chef',
    ];

    /**
     * Start a bartender/chef's first shift of the day. Requires a reviewed,
     * solo opening count (no outgoing custodian — there was nobody to hand
     * over from) naming this user as the incoming custodian.
     *
     * @throws \Exception
     */
    public function startOpeningShift(User $user, string $type, CountSession $countSession): Shift
    {
        if (!in_array($type, ['bartender', 'chef'], true)) {
            throw new \Exception('Invalid shift type for an opening shift.');
        }

        $expectedSessionType = array_search($type, self::TYPE_FOR_HANDOVER, true);

        if ($countSession->type !== $expectedSessionType) {
            throw new \Exception("This count session is not a {$type} count.");
        }

        if (!$countSession->isReviewed()) {
            throw new \Exception('The opening count must be reviewed before a shift can start from it.');
        }

        if ($countSession->outgoing_user_id !== null) {
            throw new \Exception('This was a handover count, not a solo opening count — the incoming shift already started automatically.');
        }

        if ($countSession->incoming_user_id !== $user->id && $countSession->opened_by !== $user->id) {
            throw new \Exception('This opening count is not associated with this user.');
        }

        if (Shift::query()->where('user_id', $user->id)->ofType($type)->activeNonStale($type)->exists()) {
            throw new \Exception("This user already has an active {$type} shift.");
        }

        return Shift::create([
            'user_id' => $user->id,
            'type' => $type,
            'opening_count_session_id' => $countSession->id,
            'started_at' => now(),
            'status' => 'active',
        ]);
    }

    /**
     * The handover count IS the shift boundary: finalizing a bar/kitchen
     * handover session ends the outgoing custodian's shift and starts the
     * incoming custodian's shift in the same moment.
     */
    public function applyHandoverShiftBoundary(CountSession $session): void
    {
        $type = self::TYPE_FOR_HANDOVER[$session->type] ?? null;

        if (!$type || !$session->outgoing_user_id || !$session->incoming_user_id) {
            return;
        }

        Shift::query()
            ->where('user_id', $session->outgoing_user_id)
            ->ofType($type)
            ->active()
            ->update(['ended_at' => now(), 'status' => 'closed']);

        Shift::create([
            'user_id' => $session->incoming_user_id,
            'type' => $type,
            'opening_count_session_id' => $session->id,
            'started_at' => now(),
            'status' => 'active',
        ]);
    }
}
