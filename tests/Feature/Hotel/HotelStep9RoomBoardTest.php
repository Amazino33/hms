<?php

use App\Filament\Pages\FolioDetail;
use App\Filament\Pages\RoomBoard;
use App\Models\Room;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ReservationService;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Step 9 of the hotel module: the Room Board home screen. Also pins the
 * Room::occupancyState() fix that made this screen trustworthy — it's now
 * status-aware (requires an actual check-in for 'occupied'/'due_out_today',
 * and never counts a cancelled/no_show booking), not just date-range math.
 */
function actingRoomBoardAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    return $admin;
}

it('shows a vacant room with no booking', function () {
    Room::create(['number' => '901', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $admin = actingRoomBoardAdmin();

    $tiles = Livewire::actingAs($admin)->test(RoomBoard::class)->instance()->getViewData()['tiles'];
    $tile = $tiles->firstWhere(fn ($t) => $t['room']->number === '901');

    expect($tile['occupancy'])->toBe('vacant');
    expect($tile['booking'])->toBeNull();
});

it('shows a reserved same-day booking as arriving today, with the booking attached', function () {
    $room = Room::create(['number' => '902', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $user = User::factory()->create();
    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Board Guest', 'guest_phone' => '0810' . fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $user->id);
    $admin = actingRoomBoardAdmin();

    $tiles = Livewire::actingAs($admin)->test(RoomBoard::class)->instance()->getViewData()['tiles'];
    $tile = $tiles->firstWhere(fn ($t) => $t['room']->number === '902');

    expect($tile['occupancy'])->toBe('arriving_today');
    expect($tile['booking']->id)->toBe($booking->id);
});

it('shows a checked-in booking as occupied', function () {
    $room = Room::create(['number' => '903', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $user = User::factory()->create();
    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Occupied Guest', 'guest_phone' => '0811' . fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $user->id);
    (new BookingService())->checkIn($booking, $user->id);
    $admin = actingRoomBoardAdmin();

    $tiles = Livewire::actingAs($admin)->test(RoomBoard::class)->instance()->getViewData()['tiles'];
    $tile = $tiles->firstWhere(fn ($t) => $t['room']->number === '903');

    expect($tile['occupancy'])->toBe('occupied');
    expect($tile['booking']->status)->toBe('checked_in');
});

it('never shows a cancelled booking as occupying the room', function () {
    $room = Room::create(['number' => '904', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $user = User::factory()->create();
    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Cancelled Guest', 'guest_phone' => '0812' . fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $user->id);
    (new ReservationService())->cancelReservation($booking, 'guest called off', $user->id);
    $admin = actingRoomBoardAdmin();

    $tiles = Livewire::actingAs($admin)->test(RoomBoard::class)->instance()->getViewData()['tiles'];
    $tile = $tiles->firstWhere(fn ($t) => $t['room']->number === '904');

    expect($tile['occupancy'])->toBe('vacant');
    expect($tile['booking'])->toBeNull();
});

it('never shows an overdue reservation (still reserved, date already passed) as occupied', function () {
    $room = Room::create(['number' => '905', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $user = User::factory()->create();
    (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'No Show Yet', 'guest_phone' => '0813' . fake()->numerify('#######'),
        'check_in' => now()->subDay()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $user->id);

    // Still 'reserved' — never actually checked in. occupancyState() must
    // not call this 'occupied' just because the date range covers today.
    expect($room->fresh()->occupancyState())->toBe('vacant');
});

it('shows a maintenance room regardless of any booking', function () {
    $room = Room::create(['number' => '906', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'maintenance', 'housekeeping' => 'clean']);
    $admin = actingRoomBoardAdmin();

    $tiles = Livewire::actingAs($admin)->test(RoomBoard::class)->instance()->getViewData()['tiles'];
    $tile = $tiles->firstWhere(fn ($t) => $t['room']->number === '906');

    expect($tile['occupancy'])->toBe('maintenance');
});

it('shows a Check In action on the folio detail page for a reserved booking', function () {
    Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);
    $room = Room::create(['number' => '907', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'super_admin']));
    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Folio Checkin Guest', 'guest_phone' => '0814' . fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $user->id);

    $response = $this->actingAs($user)->get('/admin/folio?booking=' . $booking->id);
    $response->assertOk();
    $response->assertSee('Check In');
    $response->assertSee('wire:click="checkIn"', false);
});

it('renders the room board page over HTTP for a receptionist', function () {
    Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);
    Room::create(['number' => '908', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $receptionist = User::factory()->create();
    $receptionist->assignRole(Role::firstOrCreate(['name' => 'receptionist']));

    $response = $this->actingAs($receptionist)->get('/admin/room-board');

    $response->assertOk();
    $response->assertSee('908');
});
