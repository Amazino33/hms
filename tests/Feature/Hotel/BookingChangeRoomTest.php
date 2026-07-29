<?php

use App\Filament\Pages\FolioDetail;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomChange;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

function makeChangeRoomRoom(string $number, float $rate): Room
{
    return Room::create(['number' => $number, 'type' => 'Standard', 'price_per_night' => $rate, 'status' => 'available', 'housekeeping' => 'clean']);
}

function makeCheckedInBookingForRoomChange(Room $room, User $user, int $nights = 4): Booking
{
    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Guest', 'guest_phone' => '08030000001',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDays($nights)->toDateString(), 'deposit' => null,
    ], $user->id);

    return (new BookingService())->checkIn($booking, $user->id);
}

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('moves a checked-in booking to a new room and updates room_id/nightly_rate', function () {
    CarbonImmutable::setTestNow('2026-07-29 12:00:00');
    $oldRoom = makeChangeRoomRoom('601', 20000);
    $newRoom = makeChangeRoomRoom('602', 25000);
    $user = User::factory()->create();
    $booking = makeCheckedInBookingForRoomChange($oldRoom, $user, nights: 4);

    $changed = (new BookingService())->changeRoom($booking, $newRoom->id, 'maintenance_fault', 'AC not working', $user->id);

    expect($changed->room_id)->toBe($newRoom->id);
    expect((float) $changed->nightly_rate)->toBe(25000.0);
});

it('credits remaining nights at the old rate and rebills them at the new rate, leaving the original charge untouched', function () {
    CarbonImmutable::setTestNow('2026-07-29 12:00:00');
    $oldRoom = makeChangeRoomRoom('603', 20000);
    $newRoom = makeChangeRoomRoom('604', 25000);
    $user = User::factory()->create();
    $booking = makeCheckedInBookingForRoomChange($oldRoom, $user, nights: 4);

    $originalRoomChargeLine = $booking->folio->lines()->where('type', 'room_charge')->first();
    expect((float) $originalRoomChargeLine->amount)->toBe(80000.0); // 4 nights * 20000

    $changed = (new BookingService())->changeRoom($booking, $newRoom->id, 'guest_preference', null, $user->id);

    // Original line untouched (append-only ledger).
    expect((float) $originalRoomChargeLine->fresh()->amount)->toBe(80000.0);

    $roomChargeLines = $changed->folio->lines()->where('type', 'room_charge')->orderBy('id')->get();
    expect($roomChargeLines)->toHaveCount(3); // original + credit + rebill

    $credit = $roomChargeLines->get(1);
    $rebill = $roomChargeLines->get(2);

    expect((float) $credit->amount)->toBe(-80000.0); // 4 remaining nights * old rate 20000
    expect((float) $rebill->amount)->toBe(100000.0); // 4 remaining nights * new rate 25000

    expect((float) $changed->total_price)->toBe(80000.0 - 80000.0 + 100000.0);
});

it('records a RoomChange row with the reason, note, and rebilled-nights count', function () {
    CarbonImmutable::setTestNow('2026-07-29 12:00:00');
    $oldRoom = makeChangeRoomRoom('605', 20000);
    $newRoom = makeChangeRoomRoom('606', 20000);
    $user = User::factory()->create();
    $booking = makeCheckedInBookingForRoomChange($oldRoom, $user, nights: 3);

    (new BookingService())->changeRoom($booking, $newRoom->id, 'noise_complaint', 'Guest reported loud neighbors', $user->id);

    $record = RoomChange::where('booking_id', $booking->id)->first();
    expect($record)->not->toBeNull();
    expect($record->from_room_id)->toBe($oldRoom->id);
    expect($record->to_room_id)->toBe($newRoom->id);
    expect($record->reason)->toBe('noise_complaint');
    expect($record->note)->toBe('Guest reported loud neighbors');
    expect($record->remaining_nights_rebilled)->toBe(3);
    expect($record->changed_by)->toBe($user->id);
});

it('does not post credit/rebill folio lines when the old and new rooms have the same rate', function () {
    CarbonImmutable::setTestNow('2026-07-29 12:00:00');
    $oldRoom = makeChangeRoomRoom('607', 20000);
    $newRoom = makeChangeRoomRoom('608', 20000);
    $user = User::factory()->create();
    $booking = makeCheckedInBookingForRoomChange($oldRoom, $user, nights: 2);

    $changed = (new BookingService())->changeRoom($booking, $newRoom->id, 'maintenance_fault', null, $user->id);

    $roomChargeLines = $changed->folio->lines()->where('type', 'room_charge')->get();
    expect($roomChargeLines)->toHaveCount(1); // only the original check-in charge
});

it('refuses to change room for a booking that is not checked in', function () {
    $oldRoom = makeChangeRoomRoom('609', 20000);
    $newRoom = makeChangeRoomRoom('610', 20000);
    $user = User::factory()->create();

    $booking = (new ReservationService())->createReservation([
        'room_id' => $oldRoom->id, 'guest_name' => 'Guest', 'guest_phone' => '08030000002',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDays(2)->toDateString(), 'deposit' => null,
    ], $user->id);

    expect(fn () => (new BookingService())->changeRoom($booking, $newRoom->id, 'other', null, $user->id))
        ->toThrow(Exception::class, 'Only a checked-in stay can change rooms.');
});

it('refuses an invalid reason', function () {
    $oldRoom = makeChangeRoomRoom('611', 20000);
    $newRoom = makeChangeRoomRoom('612', 20000);
    $user = User::factory()->create();
    $booking = makeCheckedInBookingForRoomChange($oldRoom, $user);

    expect(fn () => (new BookingService())->changeRoom($booking, $newRoom->id, 'bogus_reason', null, $user->id))
        ->toThrow(Exception::class, 'Invalid room change reason.');
});

it('refuses to move into the same room the guest is already in', function () {
    $room = makeChangeRoomRoom('613', 20000);
    $user = User::factory()->create();
    $booking = makeCheckedInBookingForRoomChange($room, $user);

    expect(fn () => (new BookingService())->changeRoom($booking, $room->id, 'other', null, $user->id))
        ->toThrow(Exception::class);
});

it('refuses to move into a room already booked for part of the remaining stay', function () {
    CarbonImmutable::setTestNow('2026-07-29 12:00:00');
    $oldRoom = makeChangeRoomRoom('614', 20000);
    $newRoom = makeChangeRoomRoom('615', 20000);
    $user = User::factory()->create();
    $booking = makeCheckedInBookingForRoomChange($oldRoom, $user, nights: 4);

    // Someone else is already reserved into the target room starting
    // tomorrow, which falls within the remaining stay.
    (new ReservationService())->createReservation([
        'room_id' => $newRoom->id, 'guest_name' => 'Other Guest', 'guest_phone' => '08030000003',
        'check_in' => now()->addDay()->toDateString(), 'check_out' => now()->addDays(3)->toDateString(), 'deposit' => null,
    ], $user->id);

    expect(fn () => (new BookingService())->changeRoom($booking, $newRoom->id, 'other', null, $user->id))
        ->toThrow(Exception::class);

    expect($booking->fresh()->room_id)->toBe($oldRoom->id);
});

it('lets a receptionist change room through the real Folio page component', function () {
    Role::firstOrCreate(['name' => 'receptionist']);
    $oldRoom = makeChangeRoomRoom('616', 20000);
    $newRoom = makeChangeRoomRoom('617', 20000);
    $receptionist = User::factory()->create();
    $receptionist->assignRole('receptionist');
    $booking = makeCheckedInBookingForRoomChange($oldRoom, $receptionist);
    auth()->login($receptionist);

    $page = new FolioDetail();
    $page->mount(Request::create('/admin/folio', 'GET', ['booking' => $booking->id]));
    $page->newRoomId = $newRoom->id;
    $page->roomChangeReason = 'maintenance_fault';
    $page->roomChangeNote = 'AC broken';
    $page->changeRoom();

    expect($booking->fresh()->room_id)->toBe($newRoom->id);
});

it('excludes the maintenance-flagged and currently-occupied-today rooms from the change-room candidate list', function () {
    $currentRoom = makeChangeRoomRoom('618', 20000);
    $freeRoom = makeChangeRoomRoom('619', 20000);
    $maintenanceRoom = makeChangeRoomRoom('620', 20000);
    $maintenanceRoom->update(['status' => 'maintenance']);
    $occupiedRoom = makeChangeRoomRoom('621', 20000);

    $user = User::factory()->create();
    $booking = makeCheckedInBookingForRoomChange($currentRoom, $user);

    // Someone else is currently checked into $occupiedRoom.
    makeCheckedInBookingForRoomChange($occupiedRoom, User::factory()->create());

    auth()->login($user);
    $page = new FolioDetail();
    $page->mount(Request::create('/admin/folio', 'GET', ['booking' => $booking->id]));

    $candidateIds = $page->candidateRoomsForChange()->pluck('id')->all();

    expect($candidateIds)->toContain($freeRoom->id);
    expect($candidateIds)->not->toContain($currentRoom->id);
    expect($candidateIds)->not->toContain($maintenanceRoom->id);
    expect($candidateIds)->not->toContain($occupiedRoom->id);
});
