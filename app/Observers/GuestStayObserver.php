<?php

namespace App\Observers;

use App\Models\Booking;
use App\Services\Guest\RoomRequestApprovalService;

/**
 * Phase 5: when a stay checks out, its guest room requests that never
 * became orders — still waiting for reception, or drinks still at the bar
 * — are cancelled ("checked_out"). Listens to the Booking model's own
 * update, so BookingService::checkOut() and its folio-balance gate are
 * untouched; anything already ordered is on the folio and goes through
 * that gate as usual.
 */
class GuestStayObserver
{
    public function updated(Booking $booking): void
    {
        if ($booking->wasChanged('status') && $booking->status === 'checked_out') {
            (new RoomRequestApprovalService)->cancelForCheckout($booking);
        }
    }
}
