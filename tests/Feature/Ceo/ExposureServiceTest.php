<?php

use App\Models\Room;
use App\Models\User;
use App\Services\BookingService;
use App\Services\Ceo\ExposureService;
use App\Services\FolioService;
use App\Services\ReservationService;

/**
 * Regression: inHouseFolioBalances() used a bare status='checked_in'
 * filter — a booking whose check_out date already passed without ever
 * actually being checked out stays stuck at status='checked_in'
 * indefinitely, permanently inflating this CEO-facing "in-house exposure"
 * figure with guests who left long ago. Fixed via Booking::
 * currentlyCheckedIn(), the same date-aware scope RoomOrderService now uses.
 */
function seedCheckedInBookingWithBalance(string $roomNumber, string $checkIn, string $checkOut, float $roomCharge = 15000): \App\Models\Booking
{
    $room = Room::create(['number' => $roomNumber, 'type' => 'Standard', 'price_per_night' => $roomCharge, 'status' => 'available', 'housekeeping' => 'clean']);
    $user = User::factory()->create();

    $booking = (new ReservationService)->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Exposure Guest', 'guest_phone' => '0807'.fake()->numerify('#######'),
        'check_in' => $checkIn, 'check_out' => $checkOut, 'deposit' => null,
    ], $user->id);
    $booking = (new BookingService)->checkIn($booking, $user->id);
    (new FolioService)->postIncidental($booking->folio, 'Room service', 5000, $user->id);

    return $booking->fresh();
}

it('counts a genuinely current in-house guest folio balance as exposure', function () {
    seedCheckedInBookingWithBalance('601', now()->toDateString(), now()->addDay()->toDateString());

    // Room charge (15000, posted automatically at check-in) + the 5000 incidental.
    expect((new ExposureService)->inHouseFolioBalances())->toBe(20000.0);
});

it('excludes a stale checked_in booking whose checkout date has already passed', function () {
    seedCheckedInBookingWithBalance('602', now()->subDays(10)->toDateString(), now()->subDays(8)->toDateString());

    expect((new ExposureService)->inHouseFolioBalances())->toBe(0.0);
});

it('sums only the current guest when a stale checked_in booking exists alongside a genuine one', function () {
    seedCheckedInBookingWithBalance('603', now()->subDays(10)->toDateString(), now()->subDays(8)->toDateString());
    seedCheckedInBookingWithBalance('604', now()->toDateString(), now()->addDay()->toDateString());

    expect((new ExposureService)->inHouseFolioBalances())->toBe(20000.0);
});
