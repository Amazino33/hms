<?php

use App\Filament\Pages\FolioDetail;
use App\Models\RoomSupply;
use App\Models\Room;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\BookingService;
use App\Services\ReservationService;
use App\Services\RoomSupplyService;
use Spatie\Permission\Models\Role;

function makeCheckedInBookingForSupplies(): array
{
    $room = Room::create(['number' => '601', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $user = User::factory()->create();
    $booking = (new ReservationService)->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Supply Guest', 'guest_phone' => '0806'.fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $user->id);
    $booking = (new BookingService)->checkIn($booking, $user->id);

    $warehouse = WareHouse::firstOrCreate(['type' => 'storage'], ['name' => 'Main Store']);
    $supply = RoomSupply::create(['name' => 'Tissue Roll', 'unit' => 'roll', 'cost_per_unit' => 100]);
    app(RoomSupplyService::class)->recordPurchase($supply, $warehouse, 20, 100, $user->id);

    return [$booking, $user, $supply, $warehouse];
}

it('shows the room profit summary on the folio page', function () {
    [$booking] = makeCheckedInBookingForSupplies();
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $response = $this->actingAs($admin)->get("/admin/folio?booking={$booking->id}");

    $response->assertSuccessful();
    $response->assertSee('Room Profit');
    $response->assertSee('Revenue');
    $response->assertSee('Power Cost');
    $response->assertSee('Supplies Cost');
});

it('records room supply usage against a stay through the folio page, deducting stock', function () {
    [$booking, $user, $supply] = makeCheckedInBookingForSupplies();
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));
    auth()->login($admin);

    $page = new FolioDetail();
    $page->mount(\Illuminate\Http\Request::create('/admin/folio', 'GET', ['booking' => $booking->id]));
    $page->selectedRoomSupplyId = $supply->id;
    $page->roomSupplyQuantity = 3;
    $page->recordRoomSupplyUsage();

    expect((float) $supply->fresh()->current_stock)->toBe(17.0);
    expect($booking->fresh()->roomSupplyUsages()->count())->toBe(1);

    $usage = $booking->fresh()->roomSupplyUsages()->first();
    expect((float) $usage->quantity)->toBe(3.0);
    expect($usage->totalCost())->toBe(300.0);
});
