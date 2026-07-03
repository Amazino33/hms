<?php

use App\Filament\Pages\TableDetail;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function makeReadyOrder(User $waiter, int $tableId = 1): Order
{
    DB::table('tables')->insertOrIgnore([
        'id' => $tableId, 'name' => 'Table ' . $tableId, 'capacity' => 4,
        'status' => 'occupied', 'location' => 'Main', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $category = Category::firstOrCreate(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Test Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    \App\Models\WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    \App\Models\InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => 4, 'quantity' => 10]);

    $order = Order::create([
        'order_number' => 'ORD-TEST-' . uniqid(),
        'table_id' => $tableId,
        'user_id' => $waiter->id,
        'status' => 'ready',
        'destination' => 'bar',
        'total_amount' => 1000,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'item_type' => 'product',
        'quantity' => 2,
        'unit_price' => 500,
        'subtotal' => 1000,
    ]);

    return $order;
}

/**
 * Filament Page components don't mount cleanly through Livewire::test()'s
 * snapshot protocol outside a real panel request, so we drive the page's
 * plain-PHP action method directly instead of through the wire-call layer.
 */
function callConfirmServed(User $actingAs, Order $order): void
{
    auth()->login($actingAs);

    $page = new TableDetail();
    $page->mount(Request::create('/admin/table-detail', 'GET', ['table_id' => $order->table_id]));
    $page->confirmServed($order->id);
}

it('lets the owning waiter confirm an order as served', function () {
    $waiter = User::factory()->create();
    $order = makeReadyOrder($waiter);

    callConfirmServed($waiter, $order);

    $order->refresh();
    expect($order->status)->toBe('served');
    expect($order->served_at)->not->toBeNull();
});

it('lets a manager confirm served on behalf of a waiter', function () {
    $waiter = User::factory()->create();
    $manager = User::factory()->create();
    Role::firstOrCreate(['name' => 'manager']);
    $manager->assignRole('manager');

    $order = makeReadyOrder($waiter);

    callConfirmServed($manager, $order);

    expect($order->fresh()->status)->toBe('served');
});

it('blocks a different waiter from confirming someone else\'s order as served', function () {
    $waiter = User::factory()->create();
    $otherWaiter = User::factory()->create();

    $order = makeReadyOrder($waiter);

    callConfirmServed($otherWaiter, $order);

    expect($order->fresh()->status)->toBe('ready');
});

it('refuses to mark an order served if it is not yet ready', function () {
    $waiter = User::factory()->create();
    $order = makeReadyOrder($waiter);
    $order->update(['status' => 'preparing']);

    callConfirmServed($waiter, $order);

    expect($order->fresh()->status)->toBe('preparing');
});

it('blocks POS payment for a dine-in table while an order is still only ready, not served', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'started_at' => now(), 'status' => 'active']);

    $order = makeReadyOrder($waiter);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $order->table_id)
        ->call('processPayment', [], 1000.0, 'cash');

    // No new order should have been created/finalized — the original
    // 'ready' order should be untouched, and no payment recorded.
    expect($order->fresh()->status)->toBe('ready');
    expect(\App\Models\OrderPayment::count())->toBe(0);
});

it('allows POS payment once the order has been confirmed served', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'started_at' => now(), 'status' => 'active']);

    $order = makeReadyOrder($waiter);
    $order->update(['status' => 'served', 'served_at' => now()]);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $order->table_id)
        ->call('processPayment', [], 1000.0, 'cash');

    expect(\App\Models\OrderPayment::count())->toBe(1);
});
