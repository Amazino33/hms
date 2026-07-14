<?php

use App\Filament\Pages\ReservationsTimeline;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ReservationService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Step 3 of the hotel module: the reserved -> checked_in transition and
 * room-charge posting. There is no separate "booking token" — a checked-in
 * booking's own status is what authorizes a room order or folio charge
 * against it (confirmed decision, not a gap).
 */
function makeStep3Room(string $number = '301', float $rate = 20000): Room
{
    return Room::create(['number' => $number, 'type' => 'Standard', 'price_per_night' => $rate, 'status' => 'available', 'housekeeping' => 'clean']);
}

it('checks in a reserved booking, posting a room charge to the folio', function () {
    $room = makeStep3Room(rate: 20000);
    $user = User::factory()->create();

    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Guest', 'guest_phone' => '08020000001',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDays(2)->toDateString(), 'deposit' => null,
    ], $user->id);

    $checkedIn = (new BookingService())->checkIn($booking, $user->id);

    expect($checkedIn->status)->toBe('checked_in');
    expect($checkedIn->checked_in_at)->not->toBeNull();
    expect($checkedIn->checked_in_by)->toBe($user->id);

    $roomChargeLine = $checkedIn->folio->lines()->where('type', 'room_charge')->first();
    expect($roomChargeLine)->not->toBeNull();
    expect((float) $roomChargeLine->amount)->toBe(40000.0);
    expect($checkedIn->folio->balance())->toBe(40000.0);
});

it('keeps the deposit line and adds the room charge on top of it', function () {
    $room = makeStep3Room();
    $user = User::factory()->create();

    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Guest', 'guest_phone' => '08020000002',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => 5000,
    ], $user->id);

    $checkedIn = (new BookingService())->checkIn($booking, $user->id);

    // Deposit (-5000) + room charge (+20000) = 15000 still owed.
    expect($checkedIn->folio->balance())->toBe(15000.0);
    expect($checkedIn->folio->lines()->count())->toBe(2);
});

it('refuses to check in a booking that is not in reserved status', function () {
    $room = makeStep3Room();
    $user = User::factory()->create();

    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Guest', 'guest_phone' => '08020000003',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $user->id);

    $service = new BookingService();
    $service->checkIn($booking, $user->id);

    expect(fn () => $service->checkIn($booking->fresh(), $user->id))->toThrow(Exception::class);
});

it('refuses to check in a cancelled booking', function () {
    $room = makeStep3Room();
    $user = User::factory()->create();

    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Guest', 'guest_phone' => '08020000004',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $user->id);
    (new ReservationService())->cancelReservation($booking, 'guest called off', $user->id);

    expect(fn () => (new BookingService())->checkIn($booking->fresh(), $user->id))->toThrow(Exception::class);
});

it('reports checked-in as the room occupancy state once checked in', function () {
    $room = makeStep3Room();
    $user = User::factory()->create();

    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Guest', 'guest_phone' => '08020000005',
        'check_in' => now()->subDay()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $user->id);
    (new BookingService())->checkIn($booking, $user->id);

    // Occupancy is still derived purely from date-range membership, not
    // booking status — checked_in vs. reserved is a receptionist-facing
    // distinction, not a change to how Room::occupancyState() works.
    expect($room->fresh()->occupancyState())->toBe('occupied');
});

it('drives the real timeline component end to end: create a reservation, then check it in', function () {
    $room = makeStep3Room('305');
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $component = Livewire::actingAs($admin)->test(ReservationsTimeline::class);

    $component->call('openForm', $room->id, now()->toDateString());
    $component->set('guestName', 'Component Guest');
    $component->set('guestPhone', '08020000006');
    $component->call('submit');

    $booking = Booking::where('room_id', $room->id)->where('status', 'reserved')->firstOrFail();
    expect($booking->guest->name)->toBe('Component Guest');

    $component->call('openDetails', $booking->id);
    expect($component->instance()->selectedBooking()->id)->toBe($booking->id);

    $component->call('checkInSelected');
    expect($booking->fresh()->status)->toBe('checked_in');
});
