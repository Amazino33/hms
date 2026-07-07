<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Regression coverage for a real production bug: processPayment()/checkout()
 * only ever sent a Filament notification and returned void on a blocked
 * attempt (unsent items, order still cooking, not yet served, etc). The
 * Alpine confirmPayment()/sendToKitchen() handlers awaited the call and then
 * unconditionally closed the modal / cleared the cart regardless of outcome,
 * so a blocked payment looked identical to a successful one from the
 * waiter's side — it silently "kicked them back" to the order screen with
 * nothing actually paid. Both methods now return a bool the client checks
 * before resetting its UI.
 */
function seedPaymentReturnFixtures(): array
{
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    DB::table('tables')->insert([
        'id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'occupied', 'location' => 'Main',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    \App\Models\WareHouse::create(['id' => 4, 'name' => 'Bar', 'location' => 'Back', 'is_active' => 1]);
    \App\Models\InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => 4, 'quantity' => 10]);

    return compact('beer');
}

it('returns false and does not fire order-completed when payment is blocked because the order is not yet served', function () {
    $user = User::factory()->create();
    Shift::create(['user_id' => $user->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    ['beer' => $beer] = seedPaymentReturnFixtures();

    $order = Order::create([
        'order_number' => 'ORD-TEST-' . time(),
        'table_id' => 1,
        'user_id' => $user->id,
        'status' => 'ready', // finished cooking, but not yet carried to the table
        'destination' => 'bar',
        'total_amount' => 1000,
    ]);
    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $beer->id,
        'product_name' => $beer->name,
        'item_type' => 'product',
        'quantity' => 2,
        'unit_price' => $beer->price,
        'subtotal' => 1000,
    ]);

    Livewire::actingAs($user)
        ->test('pos')
        ->set('selectedTableId', 1)
        ->set('existingItems', [$beer->id => ['id' => $beer->id, 'name' => $beer->name, 'price' => 500, 'quantity' => 2]])
        ->call('processPayment', [], 1000.0, 'cash')
        ->assertReturned(false)
        ->assertNotDispatched('order-completed');

    $order->refresh();
    expect($order->status)->toBe('ready');
    expect((float) $order->amount_paid)->toBe(0.0);
});

it('returns true and fires order-completed when a payment actually succeeds', function () {
    $user = User::factory()->create();
    Shift::create(['user_id' => $user->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    ['beer' => $beer] = seedPaymentReturnFixtures();

    $cart = [$beer->id => ['name' => $beer->name, 'price' => $beer->price, 'quantity' => 2]];

    Livewire::actingAs($user)
        ->test('pos')
        ->set('selectedTableId', 1)
        ->set('existingItems', $cart)
        ->call('processPayment', [], 1000.0, 'cash')
        ->assertReturned(true)
        ->assertDispatched('order-completed');
});

it('returns false when checkout is blocked because no table is selected', function () {
    $user = User::factory()->create();
    Shift::create(['user_id' => $user->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    ['beer' => $beer] = seedPaymentReturnFixtures();

    Livewire::actingAs($user)
        ->test('pos')
        ->set('selectedTableId', null)
        ->call('checkout', [$beer->id => ['name' => $beer->name, 'price' => $beer->price, 'quantity' => 1]])
        ->assertReturned(false);

    expect(Order::count())->toBe(0);
});

it('returns true when checkout succeeds', function () {
    $user = User::factory()->create();
    Shift::create(['user_id' => $user->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    ['beer' => $beer] = seedPaymentReturnFixtures();

    Livewire::actingAs($user)
        ->test('pos')
        ->set('selectedTableId', 1)
        ->call('checkout', [$beer->id => ['name' => $beer->name, 'price' => $beer->price, 'quantity' => 1]])
        ->assertReturned(true);

    expect(Order::count())->toBe(1);
});
