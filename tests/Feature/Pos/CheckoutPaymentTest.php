<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function seedPosFixtures(): array
{
    $drinkCat = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $foodCat = Category::create(['name' => 'Food', 'type' => 'food']);

    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $drinkCat->id, 'is_active' => true]);
    $rice = Product::create(['name' => 'Rice', 'price' => 1000, 'category_id' => $foodCat->id, 'is_active' => true]);

    DB::table('tables')->insert([
        'id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    \App\Models\WareHouse::create(['id' => 4, 'name' => 'Bar', 'location' => 'Back', 'is_active' => 1]);
    \App\Models\WareHouse::create(['id' => 5, 'name' => 'Kitchen', 'location' => 'Ground', 'is_active' => 1]);

    \App\Models\InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => 4, 'quantity' => 10]);
    \App\Models\InventoryItem::create(['product_id' => $rice->id, 'warehouse_id' => 5, 'quantity' => 10]);

    return compact('beer', 'rice');
}

it('records a separate OrderPayment for each destination order in a mixed cart', function () {
    $user = User::factory()->create();
    Shift::create(['user_id' => $user->id, 'started_at' => now(), 'status' => 'active']);

    ['beer' => $beer, 'rice' => $rice] = seedPosFixtures();

    $cart = [
        $beer->id => ['name' => $beer->name, 'price' => $beer->price, 'quantity' => 2], // 1000
        $rice->id => ['name' => $rice->name, 'price' => $rice->price, 'quantity' => 1], // 1000
    ];

    Livewire::actingAs($user)
        ->test('pos')
        ->set('selectedTableId', 1)
        ->set('existingItems', $cart)
        ->call('processPayment', [], 2000.0, 'cash');

    $orders = Order::where('table_id', 1)->get();
    expect($orders)->toHaveCount(2);

    // Every split order should have its own payment record.
    expect(OrderPayment::count())->toBe(2);

    $totalPaid = OrderPayment::sum('amount');
    expect((float) $totalPaid)->toBe(2000.0);

    foreach ($orders as $order) {
        expect(OrderPayment::where('order_id', $order->id)->exists())->toBeTrue();
    }
});

it('creates a partial order when a guest pays less than the total', function () {
    $user = User::factory()->create();
    Shift::create(['user_id' => $user->id, 'started_at' => now(), 'status' => 'active']);

    ['beer' => $beer] = seedPosFixtures();
    $guest = \App\Models\Guest::create(['name' => 'Debt Guest', 'phone' => '080000000']);

    $cart = [
        $beer->id => ['name' => $beer->name, 'price' => $beer->price, 'quantity' => 2], // 1000 total
    ];

    Livewire::actingAs($user)
        ->test('pos')
        ->set('selectedTableId', 1)
        ->set('existingItems', $cart)
        ->call('processPayment', [], 400.0, 'cash', $guest->id);

    $order = Order::where('table_id', 1)->firstOrFail();

    expect($order->status)->toBe('partial');
    expect((float) $order->amount_paid)->toBe(400.0);
});

it('recalculates order total_amount after an item-level return', function () {
    $user = User::factory()->create();
    Shift::create(['user_id' => $user->id, 'started_at' => now(), 'status' => 'active']);

    ['beer' => $beer] = seedPosFixtures();

    // Order already sent/served, not yet paid — the realistic moment a
    // waiter would process an in-cart return (guest changes mind before
    // the bill is settled).
    $order = Order::create([
        'order_number' => 'ORD-TEST-' . time(),
        'table_id' => 1,
        'user_id' => $user->id,
        'status' => 'served',
        'destination' => 'bar',
        'total_amount' => 2000,
        'is_return' => false,
    ]);
    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $beer->id,
        'product_name' => $beer->name,
        'item_type' => 'product',
        'quantity' => 4,
        'unit_price' => $beer->price,
        'subtotal' => 2000,
    ]);

    Livewire::actingAs($user)
        ->test('pos')
        ->set('selectedTableId', 1)
        ->call('openReturnModal', $beer->id)
        ->set('returnQuantity', 1)
        ->set('returnReason', 'Guest changed mind')
        ->call('submitReturnRequest');

    $order->refresh();

    // 3 remaining beers at 500 each = 1500 — total_amount must track the
    // reduced item, not the stale pre-return total.
    expect((float) $order->total_amount)->toBe(1500.0);
    expect(OrderItem::where('order_id', $order->id)->sum('quantity'))->toBe(3);
});