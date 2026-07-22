<?php

use App\Filament\Pages\RoomOrder;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\BookingService;
use App\Services\ReservationService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Step 10 of the hotel module: the room-order ticket print event. Mirrors
 * the POS bill printer's dispatch('print-bill', ...) + window.printPOSBill
 * pattern exactly (server dispatches a browser event with plain data,
 * client-side window.open() builds and prints a popup) — no new print
 * infrastructure, confirmed reusable as-is.
 */
it('dispatches a print-room-ticket event with room, order and item details after submitting', function () {
    $room = Room::create(['number' => '1001', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $receptionist = User::factory()->create();
    $receptionist->assignRole(Role::firstOrCreate(['name' => 'receptionist']));
    $booking = (new ReservationService)->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Ticket Guest', 'guest_phone' => '0815'.fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $receptionist->id);
    (new BookingService)->checkIn($booking, $receptionist->id);

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Ticket Beer', 'price' => 900, 'category_id' => $category->id, 'is_active' => true]);
    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => 4, 'quantity' => 20]);
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);

    $component = Livewire::actingAs($receptionist)->test(RoomOrder::class);
    $component->call('selectRoom', $room->id);
    $component->call('submitOrder', [(string) $beer->id => ['name' => $beer->name, 'price' => (float) $beer->price, 'quantity' => 1]]);

    $component->assertDispatched('print-room-ticket', function (string $name, array $params) use ($room) {
        $data = $params[0];

        return $data['roomNumber'] === $room->number
            && $data['destination'] === 'Bar'
            && count($data['items']) === 1
            && $data['items'][0]['name'] === 'Ticket Beer'
            && $data['items'][0]['quantity'] === 1;
    });
});

it('dispatches one ticket per destination for a mixed-cart room order', function () {
    $room = Room::create(['number' => '1002', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $receptionist = User::factory()->create();
    $receptionist->assignRole(Role::firstOrCreate(['name' => 'receptionist']));
    $booking = (new ReservationService)->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Mixed Ticket Guest', 'guest_phone' => '0816'.fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $receptionist->id);
    (new BookingService)->checkIn($booking, $receptionist->id);

    $drinkCategory = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $foodCategory = Category::create(['name' => 'Food', 'type' => 'food']);
    $beer = Product::create(['name' => 'Mixed Beer', 'price' => 900, 'category_id' => $drinkCategory->id, 'is_active' => true]);
    $snack = Product::create(['name' => 'Mixed Snack', 'price' => 700, 'category_id' => $foodCategory->id, 'is_active' => true]);

    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    WareHouse::firstOrCreate(['id' => 5], ['name' => 'Kitchen', 'type' => 'consumer']);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => 4, 'quantity' => 20]);
    InventoryItem::create(['product_id' => $snack->id, 'warehouse_id' => 5, 'quantity' => 20]);
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'chef', 'started_at' => now(), 'status' => 'active']);

    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);

    $component = Livewire::actingAs($receptionist)->test(RoomOrder::class);
    $component->call('selectRoom', $room->id);
    $component->call('submitOrder', [
        (string) $beer->id => ['name' => $beer->name, 'price' => (float) $beer->price, 'quantity' => 1],
        (string) $snack->id => ['name' => $snack->name, 'price' => (float) $snack->price, 'quantity' => 1],
    ]);

    // assertDispatched()'s callable form only ever inspects the FIRST
    // dispatch matching the event name, so with two same-named dispatches
    // (one per destination) the raw effects array has to be read directly.
    $dispatches = collect(data_get($component->effects, 'dispatches'))
        ->where('name', 'print-room-ticket');

    expect($dispatches)->toHaveCount(2);
    expect($dispatches->pluck('params.0.destination')->sort()->values()->all())->toBe(['Bar', 'Kitchen']);
});
