<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\FolioLine;
use App\Models\Room;
use App\Models\RoomChange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Check-in and check-out. There is no separate "booking token": a
 * checked-in booking's own status is what authorizes a room order or
 * folio charge against it, so any screen that needs "the active booking
 * for room X" resolves it by querying status = 'checked_in' directly
 * rather than looking up a code.
 */
class BookingService
{
    public const ROOM_CHANGE_REASONS = [
        'maintenance_fault' => 'Maintenance fault',
        'guest_preference' => 'Guest preference',
        'noise_complaint' => 'Noise complaint',
        'upgrade' => 'Upgrade',
        'downgrade' => 'Downgrade',
        'other' => 'Other',
    ];

    public function checkIn(Booking $booking, int $checkedInByUserId): Booking
    {
        return DB::transaction(function () use ($booking, $checkedInByUserId) {
            $booking = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();

            if ($booking->status !== 'reserved') {
                throw new \Exception('Only a reserved booking can be checked in.');
            }

            $booking->update([
                'status' => 'checked_in',
                'checked_in_at' => now(),
                'checked_in_by' => $checkedInByUserId,
            ]);

            $folio = $booking->folio ?? $booking->folio()->create();

            FolioLine::create([
                'folio_id' => $folio->id,
                'type' => 'room_charge',
                'amount' => (float) $booking->total_price,
                'description' => "Room charge: {$this->nights($booking)} night(s) @ " . number_format((float) $booking->nightly_rate, 2),
                'created_by' => $checkedInByUserId,
                'shift_id' => $booking->shift_id,
            ]);

            activity('booking')
                ->performedOn($booking)
                ->causedBy(\App\Models\User::find($checkedInByUserId))
                ->log('Guest checked in');

            return $booking->fresh();
        });
    }

    /**
     * A guest already in the room decides to stay longer and pay again —
     * pushes check_out out by $additionalNights, posts a new room_charge
     * line for just those nights (folio lines are append-only, so the
     * original check-in room charge is never edited), and re-checks the
     * room is actually free that far out (someone else may already be
     * reserved starting right after the original check-out date).
     */
    public function extendStay(Booking $booking, int $additionalNights, int $userId): Booking
    {
        return DB::transaction(function () use ($booking, $additionalNights, $userId) {
            $booking = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();

            if ($booking->status !== 'checked_in') {
                throw new \Exception('Only a checked-in stay can be renewed.');
            }

            if ($additionalNights < 1) {
                throw new \Exception('Enter at least 1 additional night.');
            }

            $newCheckOut = $booking->check_out->copy()->addDays($additionalNights);

            $overlap = Booking::where('room_id', $booking->room_id)
                ->where('id', '!=', $booking->id)
                ->whereNotIn('status', ['cancelled', 'no_show'])
                ->where('check_in', '<', $newCheckOut->toDateString())
                ->where('check_out', '>', $booking->check_out->toDateString())
                ->exists();

            if ($overlap) {
                throw new \Exception('This room is already booked starting before that new check-out date — cannot extend that far.');
            }

            $additionalCharge = (float) $booking->nightly_rate * $additionalNights;

            $booking->update([
                'check_out' => $newCheckOut->toDateString(),
                'total_price' => (float) $booking->total_price + $additionalCharge,
            ]);

            $folio = $booking->folio ?? $booking->folio()->create();

            FolioLine::create([
                'folio_id' => $folio->id,
                'type' => 'room_charge',
                'amount' => $additionalCharge,
                'description' => "Room charge: {$additionalNights} additional night(s) @ " . number_format((float) $booking->nightly_rate, 2) . ' (stay renewed)',
                'created_by' => $userId,
                'shift_id' => $booking->shift_id,
            ]);

            activity('booking')
                ->performedOn($booking)
                ->causedBy(\App\Models\User::find($userId))
                ->withProperties(['additional_nights' => $additionalNights, 'new_check_out' => $newCheckOut->toDateString()])
                ->log('Stay renewed/extended');

            return $booking->fresh();
        });
    }

    /**
     * A guest moves to a different room mid-stay — most often a fault in
     * the original room, sometimes a requested upgrade/downgrade. The
     * SAME booking/folio moves rooms (never a new booking — continuity of
     * charges/payments matters more than a clean room-per-booking model).
     * Nights already spent stay billed at the old room's rate (that charge
     * was already posted at check-in and is never edited); nights still
     * remaining in the stay get credited back at the old rate and
     * rebilled at the new room's rate, so the guest pays what the room
     * they're actually in costs, going forward.
     */
    public function changeRoom(Booking $booking, int $newRoomId, string $reason, ?string $note, int $userId): Booking
    {
        return DB::transaction(function () use ($booking, $newRoomId, $reason, $note, $userId) {
            $booking = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();

            if ($booking->status !== 'checked_in') {
                throw new \Exception('Only a checked-in stay can change rooms.');
            }

            if (! array_key_exists($reason, self::ROOM_CHANGE_REASONS)) {
                throw new \Exception('Invalid room change reason.');
            }

            if ($newRoomId === $booking->room_id) {
                throw new \Exception('That is already this guest\'s current room.');
            }

            $newRoom = Room::where('id', $newRoomId)->lockForUpdate()->firstOrFail();

            $today = now()->toDateString();

            $overlap = Booking::where('room_id', $newRoomId)
                ->where('id', '!=', $booking->id)
                ->whereNotIn('status', ['cancelled', 'no_show'])
                ->where('check_in', '<', $booking->check_out->toDateString())
                ->where('check_out', '>', $today)
                ->exists();

            if ($overlap) {
                throw new \Exception('The new room is already booked for part of the remaining stay.');
            }

            $oldRoomId = $booking->room_id;
            $oldRate = (float) $booking->nightly_rate;
            $newRate = (float) $newRoom->price_per_night;

            $remainingNights = max(0, CarbonImmutable::parse($today)->diffInDays($booking->check_out));

            if ($remainingNights > 0 && $oldRate !== $newRate) {
                $folio = $booking->folio ?? $booking->folio()->create();

                FolioLine::create([
                    'folio_id' => $folio->id,
                    'type' => 'room_charge',
                    'amount' => -($remainingNights * $oldRate),
                    'description' => "Room change: crediting {$remainingNights} remaining night(s) at old room rate",
                    'created_by' => $userId,
                    'shift_id' => $booking->shift_id,
                ]);

                FolioLine::create([
                    'folio_id' => $folio->id,
                    'type' => 'room_charge',
                    'amount' => $remainingNights * $newRate,
                    'description' => "Room change: {$remainingNights} remaining night(s) at new room rate (Room {$newRoom->number})",
                    'created_by' => $userId,
                    'shift_id' => $booking->shift_id,
                ]);

                $booking->total_price = (float) $booking->total_price - ($remainingNights * $oldRate) + ($remainingNights * $newRate);
            }

            $booking->room_id = $newRoomId;
            $booking->nightly_rate = $newRate;
            $booking->save();

            RoomChange::create([
                'booking_id' => $booking->id,
                'from_room_id' => $oldRoomId,
                'to_room_id' => $newRoomId,
                'reason' => $reason,
                'note' => $note,
                'old_nightly_rate' => $oldRate,
                'new_nightly_rate' => $newRate,
                'remaining_nights_rebilled' => $remainingNights,
                'changed_by' => $userId,
            ]);

            activity('booking')
                ->performedOn($booking)
                ->causedBy(User::find($userId))
                ->withProperties(['from_room' => $oldRoomId, 'to_room' => $newRoomId, 'reason' => $reason])
                ->log('Room changed');

            return $booking->fresh();
        });
    }

    /**
     * The hard gate: a positive folio balance blocks checkout outright.
     * On success, freezes a snapshot of the folio as it stood at this
     * exact moment — the A4 receipt always renders from that snapshot,
     * never a live query, so it can't drift even if a transfer payment on
     * this folio is verified/rejected (still permitted post-checkout,
     * since that's a manager reconciling something that already happened,
     * not new guest activity) after the guest has already left.
     */
    public function checkOut(Booking $booking, int $checkedOutByUserId): Booking
    {
        return DB::transaction(function () use ($booking, $checkedOutByUserId) {
            $booking = Booking::where('id', $booking->id)->lockForUpdate()->firstOrFail();

            if ($booking->status !== 'checked_in') {
                throw new \Exception('Only a checked-in booking can be checked out.');
            }

            $folio = $booking->folio;
            $balance = $folio ? $folio->balance() : 0.0;

            if ($balance > 0.01) {
                throw new \Exception('Folio balance must be zero before checkout — outstanding balance: ₦' . number_format($balance, 2));
            }

            $lines = $folio ? $folio->lines()->with('createdBy')->get() : collect();

            $snapshot = [
                'generated_at' => now()->toIso8601String(),
                'room_number' => $booking->room?->number,
                'guest_name' => $booking->guest?->name,
                'check_in' => $booking->check_in->toDateString(),
                'check_out' => $booking->check_out->toDateString(),
                'balance' => $balance,
                'lines' => $lines->map(fn (FolioLine $line) => [
                    'date' => $line->created_at->toIso8601String(),
                    'type' => $line->type,
                    'description' => $line->description,
                    'amount' => (float) $line->amount,
                    'payment_method' => $line->payment_method,
                    'created_by' => $line->createdBy?->name,
                ])->values()->toArray(),
            ];

            $booking->update([
                'status' => 'checked_out',
                'checked_out_at' => now(),
                'checked_out_by' => $checkedOutByUserId,
                'checkout_snapshot' => $snapshot,
            ]);

            activity('booking')
                ->performedOn($booking)
                ->causedBy(\App\Models\User::find($checkedOutByUserId))
                ->log('Guest checked out');

            return $booking->fresh();
        });
    }

    private function nights(Booking $booking): int
    {
        return max(1, $booking->check_in->diffInDays($booking->check_out));
    }
}
