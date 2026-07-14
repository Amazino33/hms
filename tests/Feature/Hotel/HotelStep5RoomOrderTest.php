<?php

use App\Filament\Pages\BarDisplay;
use App\Filament\Pages\RoomOrder;
use App\Models\Category;
use App\Models\FolioLine;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\BookingService;
use App\Services\ReservationService;
use App\Services\RoomOrderService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Step 5 of the hotel module: room ordering. A room order is billed to the
 * folio at creation time but — unlike every other order destination —
 * defers stock deduction until the kitchen/bar display marks it Ready
 * (OrderSplitter's defer_stock_deduction option). There is no room-order
 * waiter shift requirement (a receptionist places it, not a waiter); the
 * per-destination bartender/chef shift checks still apply unchanged.
 */
function seedRoomOrderFixture(): array
{
    $room = Room::create(['number' => '501', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $receptionist = User::factory()->create();

    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Room Order Guest', 'guest_phone' => '0804' . fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $receptionist->id);
    $booking = (new BookingService())->checkIn($booking, $receptionist->id);

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Room Beer', 'price' => 800, 'category_id' => $category->id, 'is_active' => true]);
    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => 4, 'quantity' => 30]);

    return [$room, $booking, $receptionist, $beer];
}

it('places a room order without a waiter shift, billing the folio but not touching stock yet', function () {
    [$room, $booking, $receptionist, $beer] = seedRoomOrderFixture();
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $stockBefore = (int) InventoryItem::where('product_id', $beer->id)->where('warehouse_id', 4)->value('quantity');

    $cart = [(string) $beer->id => ['name' => $beer->name, 'price' => 800, 'quantity' => 2]];
    $orders = (new RoomOrderService())->placeOrder($room->id, $cart, $receptionist->id);

    expect($orders)->toHaveCount(1);
    $order = $orders[0];
    expect($order->booking_id)->toBe($booking->id);
    expect($order->destination)->toBe('bar');
    expect($order->status)->toBe('pending');
    expect((float) $order->total_amount)->toBe(1600.0);

    // Stock untouched at creation.
    $stockAfterCreate = (int) InventoryItem::where('product_id', $beer->id)->where('warehouse_id', 4)->value('quantity');
    expect($stockAfterCreate)->toBe($stockBefore);

    // Folio billed immediately.
    $folioLine = $booking->folio->fresh()->lines()->where('type', 'order')->first();
    expect($folioLine)->not->toBeNull();
    expect((float) $folioLine->amount)->toBe(1600.0);
});

it('deducts stock exactly once, at the moment the bar display marks the room order ready', function () {
    [$room, $booking, $receptionist, $beer] = seedRoomOrderFixture();
    $bartender = User::factory()->create();
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);
    $bartender->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $stockBefore = (int) InventoryItem::where('product_id', $beer->id)->where('warehouse_id', 4)->value('quantity');

    $cart = [(string) $beer->id => ['name' => $beer->name, 'price' => 800, 'quantity' => 3]];
    $orders = (new RoomOrderService())->placeOrder($room->id, $cart, $receptionist->id);
    $order = $orders[0];

    $component = Livewire::actingAs($bartender)->test(BarDisplay::class);
    $component->call('markAsReady', $order->id);

    expect($order->fresh()->status)->toBe('ready');
    $stockAfter = (int) InventoryItem::where('product_id', $beer->id)->where('warehouse_id', 4)->value('quantity');
    expect($stockAfter)->toBe($stockBefore - 3);
});

it('rejects a room order for a room with no checked-in booking', function () {
    $room = Room::create(['number' => '502', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $receptionist = User::factory()->create();

    expect(fn () => (new RoomOrderService())->placeOrder($room->id, ['1' => ['name' => 'x', 'price' => 100, 'quantity' => 1]], $receptionist->id))
        ->toThrow(Exception::class);
});

it('still requires an active bartender shift for a room order routed to the bar', function () {
    [$room, $booking, $receptionist, $beer] = seedRoomOrderFixture();
    // Deliberately no bartender shift started.

    $cart = [(string) $beer->id => ['name' => $beer->name, 'price' => 800, 'quantity' => 1]];

    expect(fn () => (new RoomOrderService())->placeOrder($room->id, $cart, $receptionist->id))->toThrow(Exception::class);
});

it('shows "Room N" as the origin label for a room order instead of Takeaway', function () {
    [$room, $booking, $receptionist, $beer] = seedRoomOrderFixture();
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $cart = [(string) $beer->id => ['name' => $beer->name, 'price' => 800, 'quantity' => 1]];
    $order = (new RoomOrderService())->placeOrder($room->id, $cart, $receptionist->id)[0];

    expect($order->fresh()->origin_label)->toBe('Room 501');
});

it('drives the room order screen end to end: pick a checked-in room, add items, submit', function () {
    [$room, $booking, $receptionist, $beer] = seedRoomOrderFixture();
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);
    $receptionist->assignRole(Role::firstOrCreate(['name' => 'receptionist']));

    $component = Livewire::actingAs($receptionist)->test(RoomOrder::class);
    $component->call('selectRoom', $room->id);
    expect($component->get('bookingId'))->toBe($booking->id);

    $component->call('addProductToCart', $beer->id, $beer->name, (float) $beer->price);
    expect($component->get('cart'))->toHaveKey((string) $beer->id);

    $component->call('submitOrder');

    expect(\App\Models\Order::where('booking_id', $booking->id)->exists())->toBeTrue();
    expect($component->get('cart'))->toBe([]);
});

it('refuses to select a room with no checked-in booking on the room order screen', function () {
    $room = Room::create(['number' => '503', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $component = Livewire::actingAs($admin)->test(RoomOrder::class);
    $component->call('selectRoom', $room->id);

    expect($component->get('roomId'))->toBeNull();
});
