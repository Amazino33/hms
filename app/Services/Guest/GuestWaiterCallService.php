<?php

namespace App\Services\Guest;

use App\Models\GuestTableSession;
use App\Models\GuestWaiterCall;
use App\Models\Room;
use App\Models\Table;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Call waiter" (Phase 4, D22). Ice, Cups, Bill or Other (a 60-character
 * note). Shown on the waiter strip — the table's assigned waiter's, or
 * everyone's when nobody has the table yet. "On my way" is one tap, no PIN.
 * Calls expire after 15 minutes.
 *
 * Rooms (Phase 5, D28): "Call reception" — Ice, Cups, Cutlery or Other,
 * only during a stay, shown on the reception Room Orders page.
 */
class GuestWaiterCallService
{
    public const NOTE_MAX = 60;

    public const DEVICE_GAP_SECONDS = 120;

    public const MAX_OPEN_PER_TABLE = 3;

    /**
     * @throws GuestRequestException
     */
    public function create(string $token, string $deviceId, mixed $reason, mixed $note = null): GuestWaiterCall
    {
        $place = QrTokens::resolve($token);

        if (! $place) {
            throw new GuestRequestException('invalid_code', 'This code is no longer valid — please ask a staff member.', 404);
        }

        $isRoom = $place instanceof Room;
        $reasons = $isRoom ? GuestWaiterCall::ROOM_REASONS : GuestWaiterCall::TABLE_REASONS;

        if (! is_string($reason) || ! in_array($reason, $reasons, true)) {
            throw new GuestRequestException('bad_reason', $isRoom
                ? 'Pick what you need: Ice, Cups, Cutlery or Other.'
                : 'Pick what you need: Ice, Cups, Bill or Other.');
        }

        $note = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $note)));

        if ($reason === 'other' && $note === '') {
            throw new GuestRequestException('bad_note', $isRoom ? 'Tell reception what you need.' : 'Tell your waiter what you need.');
        }

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new GuestRequestException('bad_note', 'Keep it to '.self::NOTE_MAX.' characters.');
        }

        if ($isRoom) {
            return $this->createForRoom($place, $deviceId, $reason, $note);
        }

        return DB::transaction(function () use ($place, $deviceId, $reason, $note) {
            $table = Table::whereKey($place->id)->lockForUpdate()->firstOrFail();

            $recent = GuestWaiterCall::where('device_id', $deviceId)
                ->where('created_at', '>', now()->subSeconds(self::DEVICE_GAP_SECONDS))
                ->exists();

            if ($recent) {
                throw new GuestRequestException('call_too_soon', 'You just called — please wait a moment.', 429);
            }

            if (GuestWaiterCall::where('table_id', $table->id)->live()->count() >= self::MAX_OPEN_PER_TABLE) {
                throw new GuestRequestException('call_busy', 'Your waiter has already been called — they\'ll be with you shortly.', 429);
            }

            $session = GuestTableSession::open()->where('table_id', $table->id)->first();
            $session?->update(['last_activity_at' => now()]);

            return GuestWaiterCall::create([
                'guest_table_session_id' => $session?->id,
                'table_id' => $table->id,
                'device_id' => $deviceId,
                'reason' => $reason,
                'note' => $note === '' ? null : $note,
                'status' => GuestWaiterCall::STATUS_OPEN,
            ]);
        });
    }

    /**
     * @throws GuestRequestException
     */
    private function createForRoom(Room $room, string $deviceId, string $reason, string $note): GuestWaiterCall
    {
        return DB::transaction(function () use ($room, $deviceId, $reason, $note) {
            $room = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();

            if (! GuestRequestService::currentStay($room)) {
                throw new GuestRequestException('not_staying', 'Please call reception from your room phone.', 422);
            }

            if (GuestWaiterCall::where('device_id', $deviceId)->where('created_at', '>', now()->subSeconds(self::DEVICE_GAP_SECONDS))->exists()) {
                throw new GuestRequestException('call_too_soon', 'You just called — please wait a moment.', 429);
            }

            if (GuestWaiterCall::where('room_id', $room->id)->live()->count() >= self::MAX_OPEN_PER_TABLE) {
                throw new GuestRequestException('call_busy', 'Reception has already been called — someone will be with you shortly.', 429);
            }

            return GuestWaiterCall::create([
                'room_id' => $room->id,
                'device_id' => $deviceId,
                'reason' => $reason,
                'note' => $note === '' ? null : $note,
                'status' => GuestWaiterCall::STATUS_OPEN,
            ]);
        });
    }

    /**
     * One tap, no PIN. Records whoever is signed in on that screen, if
     * anyone. A call already answered or expired is left as it is.
     */
    public function acknowledge(GuestWaiterCall $call, ?User $by): bool
    {
        return DB::transaction(function () use ($call, $by) {
            $call = GuestWaiterCall::lockForUpdate()->find($call->id);

            if (! $call || $call->status !== GuestWaiterCall::STATUS_OPEN) {
                return false;
            }

            $call->update([
                'status' => GuestWaiterCall::STATUS_ACKNOWLEDGED,
                'acknowledged_by_user_id' => $by?->id,
                'acknowledged_at' => now(),
            ]);

            return true;
        });
    }

    /** guest:expire-stale — open calls older than 15 minutes. */
    public function expireStale(): int
    {
        return GuestWaiterCall::where('status', GuestWaiterCall::STATUS_OPEN)
            ->where('created_at', '<=', now()->subMinutes(GuestWaiterCall::EXPIRES_AFTER_MINUTES))
            ->update(['status' => GuestWaiterCall::STATUS_EXPIRED]);
    }
}
