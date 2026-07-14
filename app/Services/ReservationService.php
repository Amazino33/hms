<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Guest;
use App\Models\Room;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reservation creation, including the one guarantee this whole hotel
 * module depends on: two overlapping bookings for the same room must be
 * structurally impossible, even under concurrent requests — not just a
 * UI check. MySQL has no native date-range exclusion constraint, so this
 * is enforced the accepted way for this database: lock the room row
 * inside a transaction before checking for an overlap, so a second
 * concurrent request for the same room blocks on the lock until the
 * first transaction commits (or rolls back), rather than racing it.
 */
class ReservationService
{
    /**
     * @param array{room_id: int, guest_name: string, guest_phone: ?string, check_in: string, check_out: string, deposit: ?float} $data
     */
    public function createReservation(array $data, ?int $createdByUserId, ?int $shiftId = null): Booking
    {
        return DB::transaction(function () use ($data, $createdByUserId, $shiftId) {
            $room = Room::where('id', $data['room_id'])->lockForUpdate()->firstOrFail();

            $checkIn = Carbon::parse($data['check_in'])->toDateString();
            $checkOut = Carbon::parse($data['check_out'])->toDateString();

            if ($checkOut <= $checkIn) {
                throw new \Exception('Check-out must be after check-in.');
            }

            $this->assertNoOverlap($room->id, $checkIn, $checkOut);

            $guest = $this->resolveGuest($data['guest_name'], $data['guest_phone'] ?? null);

            $nights = max(1, Carbon::parse($checkIn)->diffInDays(Carbon::parse($checkOut)));
            $nightlyRate = (float) $room->price_per_night;
            $deposit = (float) ($data['deposit'] ?? 0);

            $booking = Booking::create([
                'guest_id' => $guest->id,
                'room_id' => $room->id,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'nightly_rate' => $nightlyRate,
                'total_price' => $nightlyRate * $nights,
                'deposit' => $deposit > 0 ? $deposit : null,
                'status' => 'reserved',
                'is_paid' => false,
                'created_by_user_id' => $createdByUserId,
                'shift_id' => $shiftId,
            ]);

            $folio = Folio::create(['booking_id' => $booking->id]);

            if ($deposit > 0) {
                $depositMethod = $data['deposit_method'] ?? 'cash';

                FolioLine::create([
                    'folio_id' => $folio->id,
                    'type' => 'payment',
                    'amount' => -$deposit,
                    'description' => 'Deposit at reservation',
                    'created_by' => $createdByUserId,
                    // Explicit override wins; otherwise attribute to
                    // whoever's on shift right now, same as
                    // FolioService::recordPayment() — needed for
                    // ReceptionistShiftService's cash-collected math.
                    'shift_id' => $shiftId ?? \App\Models\User::find($createdByUserId)?->currentShift()?->id,
                    'payment_method' => $depositMethod,
                    'verified' => $depositMethod !== 'transfer',
                ]);
            }

            return $booking->fresh();
        });
    }

    /**
     * Cancelled/no-show bookings never block a new reservation for the
     * same room/dates — only a genuinely active (reserved/checked-in)
     * booking counts as an overlap.
     */
    private function assertNoOverlap(int $roomId, string $checkIn, string $checkOut): void
    {
        $overlap = Booking::where('room_id', $roomId)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->where('check_in', '<', $checkOut)
            ->where('check_out', '>', $checkIn)
            ->exists();

        if ($overlap) {
            throw new \Exception('This room is already booked for an overlapping date range.');
        }
    }

    private function resolveGuest(string $name, ?string $phone): Guest
    {
        if ($phone) {
            $existing = Guest::where('phone', $phone)->first();

            if ($existing) {
                return $existing;
            }
        }

        return Guest::create(['name' => $name, 'phone' => $phone]);
    }

    public function cancelReservation(Booking $booking, string $reason, int $cancelledByUserId): Booking
    {
        if (in_array($booking->status, ['checked_out', 'cancelled', 'no_show'], true)) {
            throw new \Exception('This reservation has already been closed out.');
        }

        $booking->update(['status' => 'cancelled']);

        activity('booking')
            ->performedOn($booking)
            ->causedBy(\App\Models\User::find($cancelledByUserId))
            ->withProperties(['reason' => $reason])
            ->log('Reservation cancelled');

        return $booking->fresh();
    }
}
