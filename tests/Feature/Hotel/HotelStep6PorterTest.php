<?php

use App\Filament\Pages\PorterDeliveries;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\BookingService;
use App\Services\PorterDeliveryService;
use App\Services\ReservationService;
use App\Services\RoomOrderService;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Step 6 of the hotel module: porter custody tracking for room orders.
 * Pick-up and delivery are two distinct, separately attributed events —
 * mirroring ServedConfirmationService's waiter-confirms-served pattern,
 * but keyed off who physically carried the order (picked_up_by), not the
 * order's own user_id (the receptionist who placed it).
 */
function seedReadyRoomOrder(): array
{
    $room = Room::create(['number' => '601', 'type' => 'Standard', 'price_per_night' => 15000, 'status' => 'available', 'housekeeping' => 'clean']);
    $receptionist = User::factory()->create();
    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Porter Guest', 'guest_phone' => '0805' . fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $receptionist->id);
    (new BookingService())->checkIn($booking, $receptionist->id);

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Porter Beer', 'price' => 700, 'category_id' => $category->id, 'is_active' => true]);
    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => 4, 'quantity' => 20]);
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $order = (new RoomOrderService())->placeOrder($room->id, [(string) $beer->id => ['name' => $beer->name, 'price' => 700, 'quantity' => 1]], $receptionist->id)[0];
    $order->update(['status' => 'ready']); // simulate the bar marking it ready

    return [$order, $room, $booking];
}

it('lets a porter pick up a ready room order, stamping who and when', function () {
    [$order] = seedReadyRoomOrder();
    $porter = User::factory()->create();

    $picked = (new PorterDeliveryService())->pickUp($order, $porter);

    expect($picked->picked_up_by)->toBe($porter->id);
    expect($picked->picked_up_at)->not->toBeNull();
    expect($picked->status)->toBe('ready'); // pickup alone doesn't close the order
});

it('refuses to pick up an order that is not yet ready', function () {
    [$order] = seedReadyRoomOrder();
    $order->update(['status' => 'pending']);
    $porter = User::factory()->create();

    expect(fn () => (new PorterDeliveryService())->pickUp($order->fresh(), $porter))->toThrow(Exception::class);
});

it('refuses to pick up an order that is not a room order', function () {
    $order = Order::create(['order_number' => 'ORD-X', 'destination' => 'bar', 'status' => 'ready', 'total_amount' => 500]);
    $porter = User::factory()->create();

    expect(fn () => (new PorterDeliveryService())->pickUp($order, $porter))->toThrow(Exception::class);
});

it('refuses to pick up an order that has already been picked up', function () {
    [$order] = seedReadyRoomOrder();
    $porter = User::factory()->create();
    (new PorterDeliveryService())->pickUp($order, $porter);

    expect(fn () => (new PorterDeliveryService())->pickUp($order->fresh(), User::factory()->create()))->toThrow(Exception::class);
});

it('lets the porter who picked it up confirm delivery, marking the order served', function () {
    [$order] = seedReadyRoomOrder();
    $porter = User::factory()->create();
    (new PorterDeliveryService())->pickUp($order, $porter);

    $delivered = (new PorterDeliveryService())->confirmDelivered($order->fresh(), $porter);

    expect($delivered->status)->toBe('served');
    expect($delivered->served_at)->not->toBeNull();
});

it('refuses to let a different porter confirm delivery for someone else\'s pickup', function () {
    [$order] = seedReadyRoomOrder();
    $porter = User::factory()->create();
    $otherPorter = User::factory()->create();
    (new PorterDeliveryService())->pickUp($order, $porter);

    expect(fn () => (new PorterDeliveryService())->confirmDelivered($order->fresh(), $otherPorter))->toThrow(Exception::class);
});

it('lets a manager confirm delivery on behalf of the picking-up porter', function () {
    [$order] = seedReadyRoomOrder();
    $porter = User::factory()->create();
    $manager = User::factory()->create();
    $manager->assignRole(Role::firstOrCreate(['name' => 'manager']));
    (new PorterDeliveryService())->pickUp($order, $porter);

    $delivered = (new PorterDeliveryService())->confirmDelivered($order->fresh(), $manager);

    expect($delivered->status)->toBe('served');
});

it('refuses to confirm delivery before pickup', function () {
    [$order] = seedReadyRoomOrder();
    $porter = User::factory()->create();

    expect(fn () => (new PorterDeliveryService())->confirmDelivered($order, $porter))->toThrow(Exception::class);
});

it('shows the order under ready-for-pickup before pickup and under in-transit after, on the real page', function () {
    [$order] = seedReadyRoomOrder();
    Artisan::call('db:seed', ['--class' => 'PagePermissionsSeeder', '--force' => true]);
    $porter = User::factory()->create();
    $porter->assignRole(Role::firstOrCreate(['name' => 'porter']));

    $component = Livewire::actingAs($porter)->test(PorterDeliveries::class);
    $data = $component->instance()->getViewData();
    expect(collect($data['readyForPickup'])->pluck('id'))->toContain($order->id);
    expect(collect($data['inTransit'])->pluck('id'))->not->toContain($order->id);

    $component->call('pickUp', $order->id);

    $data = $component->instance()->getViewData();
    expect(collect($data['readyForPickup'])->pluck('id'))->not->toContain($order->id);
    expect(collect($data['inTransit'])->pluck('id'))->toContain($order->id);

    $component->call('confirmDelivered', $order->id);
    expect($order->fresh()->status)->toBe('served');
});
