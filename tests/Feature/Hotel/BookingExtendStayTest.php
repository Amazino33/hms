<?php

use App\Filament\Pages\FolioDetail;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ReservationService;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

function makeExtendStayRoom(string $number = '501', float $rate = 20000): Room
{
    return Room::create(['number' => $number, 'type' => 'Standard', 'price_per_night' => $rate, 'status' => 'available', 'housekeeping' => 'clean']);
}

function makeCheckedInBookingForExtendStay(Room $room, User $user, int $nights = 2): Booking
{
    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Guest', 'guest_phone' => '08020000009',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDays($nights)->toDateString(), 'deposit' => null,
    ], $user->id);

    return (new BookingService())->checkIn($booking, $user->id);
}

it('extends check-out, adds to total_price, and posts a new room_charge line for just the extra nights', function () {
    $room = makeExtendStayRoom(rate: 20000);
    $user = User::factory()->create();
    $booking = makeCheckedInBookingForExtendStay($room, $user, nights: 2);

    $originalCheckOut = $booking->check_out->toDateString();
    $originalTotal = (float) $booking->total_price;

    $extended = (new BookingService())->extendStay($booking, 3, $user->id);

    expect($extended->check_out->toDateString())->toBe(now()->addDays(2)->addDays(3)->toDateString());
    expect((float) $extended->total_price)->toBe($originalTotal + 60000.0);

    $lines = $extended->folio->lines()->where('type', 'room_charge')->get();
    expect($lines)->toHaveCount(2);
    expect((float) $lines->last()->amount)->toBe(60000.0);
    expect($lines->last()->description)->toContain('3 additional night(s)');
});

it('refuses to extend a booking that is not checked in', function () {
    $room = makeExtendStayRoom();
    $user = User::factory()->create();

    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Guest', 'guest_phone' => '08020000010',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDays(2)->toDateString(), 'deposit' => null,
    ], $user->id);

    expect(fn () => (new BookingService())->extendStay($booking, 2, $user->id))
        ->toThrow(Exception::class, 'Only a checked-in stay can be renewed.');
});

it('refuses to extend into dates another active reservation already occupies', function () {
    $room = makeExtendStayRoom();
    $user = User::factory()->create();
    $booking = makeCheckedInBookingForExtendStay($room, $user, nights: 2);

    // A different guest is already reserved for this room starting right
    // after the current check-out date.
    (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Next Guest', 'guest_phone' => '08020000011',
        'check_in' => $booking->check_out->toDateString(), 'check_out' => $booking->check_out->copy()->addDays(2)->toDateString(), 'deposit' => null,
    ], $user->id);

    expect(fn () => (new BookingService())->extendStay($booking, 1, $user->id))
        ->toThrow(Exception::class);

    expect($booking->fresh()->check_out->toDateString())->toBe($booking->check_out->toDateString());
});

it('rejects an extension of zero or negative nights', function () {
    $room = makeExtendStayRoom();
    $user = User::factory()->create();
    $booking = makeCheckedInBookingForExtendStay($room, $user, nights: 2);

    expect(fn () => (new BookingService())->extendStay($booking, 0, $user->id))
        ->toThrow(Exception::class, 'Enter at least 1 additional night.');
});

it('lets a receptionist renew a stay through the real Folio page component', function () {
    Role::firstOrCreate(['name' => 'receptionist']);
    $room = makeExtendStayRoom();
    $receptionist = User::factory()->create();
    $receptionist->assignRole('receptionist');
    $booking = makeCheckedInBookingForExtendStay($room, $receptionist, nights: 2);
    auth()->login($receptionist);

    $page = new FolioDetail();
    $page->mount(Request::create('/admin/folio', 'GET', ['booking' => $booking->id]));
    $page->extendNights = 4;
    $page->extendStay();

    $fresh = $booking->fresh();
    expect($fresh->check_out->toDateString())->toBe(now()->addDays(2)->addDays(4)->toDateString());
});
