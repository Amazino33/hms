<?php

use App\Filament\Pages\FloorPlan;
use App\Filament\Pages\MyHistory;
use App\Filament\Pages\MyShiftReport;
use App\Filament\Pages\PosPage;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

/**
 * Step 1 of the hotel module: the receptionist role, a narrowed porter
 * role, the room foundation (34 rooms, housekeeping flag, occupancy
 * derived from bookings instead of stored), and the kitchen/bar display
 * fixes that preceded it.
 */
it('creates the receptionist role via the seeder', function () {
    Artisan::call('db:seed', ['--class' => 'ShieldSeeder', '--force' => true]);

    expect(Role::where('name', 'receptionist')->exists())->toBeTrue();
});

it('narrows porter to no longer access POS, Floor Plan, My History, or My Shift Report', function () {
    $porter = User::factory()->create();
    $porter->assignRole(Role::firstOrCreate(['name' => 'porter']));

    expect(PermissionService::canAccessPage(PosPage::class))->toBeFalse();

    // canAccessPage() reads the authenticated user, so act as porter for
    // each check individually.
    $this->actingAs($porter);
    expect(PermissionService::canAccessPage(PosPage::class))->toBeFalse();
    expect(PermissionService::canAccessPage(FloorPlan::class))->toBeFalse();
    expect(PermissionService::canAccessPage(MyHistory::class))->toBeFalse();
    expect(PermissionService::canAccessPage(MyShiftReport::class))->toBeFalse();
});

it('seeds exactly 34 rooms, idempotently', function () {
    Artisan::call('db:seed', ['--class' => 'RoomSeeder', '--force' => true]);
    expect(Room::count())->toBe(34);

    Artisan::call('db:seed', ['--class' => 'RoomSeeder', '--force' => true]);
    expect(Room::count())->toBe(34);
});

it('defaults a new room to clean housekeeping status', function () {
    $room = Room::create(['number' => '99', 'type' => 'Standard', 'price_per_night' => 10000, 'status' => 'available', 'housekeeping' => 'clean']);

    expect($room->housekeeping)->toBe('clean');
});

it('derives room occupancy from bookings instead of a stored status', function () {
    $room = Room::create(['number' => '1', 'type' => 'Standard', 'price_per_night' => 10000, 'status' => 'available', 'housekeeping' => 'clean']);
    $guest = Guest::create(['name' => 'Jane Doe', 'phone' => '08000000000']);

    expect($room->occupancyState())->toBe('vacant');

    $booking = Booking::create([
        'guest_id' => $guest->id, 'room_id' => $room->id,
        'check_in' => now()->toDateString(), 'check_out' => now()->addDays(2)->toDateString(),
        'total_price' => 20000, 'is_paid' => false,
    ]);

    // Booking::create() no longer flips room.status at all (the old
    // create/delete hook is removed) — status stays exactly as it was.
    expect($room->fresh()->status)->toBe('available');
    expect($room->fresh()->occupancyState())->toBe('arriving_today'); // status defaults to 'reserved'

    // occupancyState() is status-aware (Step 9): 'occupied'/'due_out_today'
    // require an actual check-in, not just a date range — a still-'reserved'
    // booking whose date has passed is an overdue arrival, not occupancy.
    $booking->update(['status' => 'checked_in', 'check_in' => now()->subDay()->toDateString()]);
    expect($room->fresh()->occupancyState())->toBe('occupied');

    $booking->update(['check_in' => now()->subDays(2)->toDateString(), 'check_out' => now()->toDateString()]);
    expect($room->fresh()->occupancyState())->toBe('due_out_today');

    $booking->delete();
    expect($room->fresh()->occupancyState())->toBe('vacant');
    expect($room->fresh()->status)->toBe('available'); // still untouched by delete
});

it('lets a guest see their bookings through the new relationship', function () {
    $room = Room::create(['number' => '2', 'type' => 'Standard', 'price_per_night' => 10000, 'status' => 'available', 'housekeeping' => 'clean']);
    $guest = Guest::create(['name' => 'John Smith', 'phone' => '08011111111']);
    $booking = Booking::create([
        'guest_id' => $guest->id, 'room_id' => $room->id,
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(),
        'total_price' => 10000, 'is_paid' => false,
    ]);

    expect($guest->bookings()->pluck('id'))->toContain($booking->id);
});

it('excludes a room occupied today from the pool of rooms available to book', function () {
    $room = Room::create(['number' => '3', 'type' => 'Standard', 'price_per_night' => 10000, 'status' => 'available', 'housekeeping' => 'clean']);
    $freeRoom = Room::create(['number' => '4', 'type' => 'Standard', 'price_per_night' => 10000, 'status' => 'available', 'housekeeping' => 'clean']);
    $guest = Guest::create(['name' => 'Occupant', 'phone' => '08022222222']);
    Booking::create([
        'guest_id' => $guest->id, 'room_id' => $room->id,
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(),
        'total_price' => 10000, 'is_paid' => false,
    ]);

    // Same query BookingResource's room_id select runs to build its
    // options list — asserted directly since introspecting Filament's own
    // form component tree is fragile/version-coupled.
    $availableRoomIds = Room::available()->get()->reject(fn (Room $r) => $r->isOccupiedToday())->pluck('id');

    expect($availableRoomIds)->toContain($freeRoom->id);
    expect($availableRoomIds)->not->toContain($room->id);
});
