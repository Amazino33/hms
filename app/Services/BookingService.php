<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\FolioLine;
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
