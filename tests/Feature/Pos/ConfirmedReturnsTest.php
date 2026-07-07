<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\ReturnConfirmationService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function seedReturnScenario(string $destination = 'bar'): array
{
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'occupied', 'location' => 'Main']);
    $categoryType = $destination === 'bar' ? 'drink' : 'food';
    $category = Category::create(['name' => ucfirst($categoryType), 'type' => $categoryType]);
    $product = Product::create(['name' => 'Item', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    $warehouseId = $destination === 'bar' ? 4 : 5;
    $warehouse = WareHouse::firstOrCreate(['id' => $warehouseId], ['name' => ucfirst($destination), 'is_active' => 1]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouseId, 'quantity' => 10]);

    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $order = Order::create([
        'order_number' => 'ORD-' . uniqid(),
        'table_id' => $table->id,
        'user_id' => $waiter->id,
        'status' => 'served',
        'destination' => $destination,
        'total_amount' => 2000,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'item_type' => 'product',
        'quantity' => 4,
        'unit_price' => 500,
        'subtotal' => 2000,
    ]);

    return compact('table', 'product', 'waiter', 'order', 'warehouseId');
}

it('does not touch the original order bill when a return is first requested', function () {
    ['table' => $table, 'product' => $product, 'waiter' => $waiter, 'order' => $order] = seedReturnScenario('bar');

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $table->id)
        ->set('existingItems', [$product->id => ['name' => $product->name, 'price' => 500, 'quantity' => 4, 'type' => 'product', 'product_id' => $product->id]])
        ->call('openReturnModal', $product->id)
        ->set('returnQuantity', 1)
        ->set('returnReason', 'Guest changed mind')
        ->call('submitReturnRequest');

    // Original order untouched — no financial effect yet.
    $order->refresh();
    expect((float) $order->total_amount)->toEqual(2000.0);
    expect(OrderItem::where('order_id', $order->id)->sum('quantity'))->toBe(4);

    $ticket = Order::where('is_return', true)->latest('id')->first();
    expect($ticket)->not->toBeNull();
    expect($ticket->status)->toBe('pending');
    expect($ticket->destination)->toBe('bar');
    expect($ticket->shift_id)->not->toBeNull();
});

it('reduces the original order and reverses stock only once the on-duty bartender confirms', function () {
    ['table' => $table, 'product' => $product, 'waiter' => $waiter, 'order' => $order] = seedReturnScenario('bar');

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $table->id)
        ->set('existingItems', [$product->id => ['name' => $product->name, 'price' => 500, 'quantity' => 4, 'type' => 'product', 'product_id' => $product->id]])
        ->call('openReturnModal', $product->id)
        ->set('returnQuantity', 1)
        ->set('returnReason', 'Guest changed mind')
        ->call('submitReturnRequest');

    $ticket = Order::where('is_return', true)->latest('id')->first();
    $stockBefore = InventoryItem::where('product_id', $product->id)->where('warehouse_id', 4)->value('quantity');

    $bartender = User::factory()->create();
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    (new ReturnConfirmationService())->confirm($ticket, $bartender);

    $order->refresh();
    expect((float) $order->total_amount)->toEqual(1500.0); // 2000 - 500 (1 unit)
    expect(OrderItem::where('order_id', $order->id)->sum('quantity'))->toBe(3);

    $stockAfter = InventoryItem::where('product_id', $product->id)->where('warehouse_id', 4)->value('quantity');
    expect((int) $stockAfter)->toBe((int) $stockBefore + 1);

    expect($ticket->fresh()->status)->toBe('returned');
});

it('refuses to confirm a return without an active bartender shift', function () {
    ['table' => $table, 'product' => $product, 'waiter' => $waiter, 'order' => $order] = seedReturnScenario('bar');

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $table->id)
        ->set('existingItems', [$product->id => ['name' => $product->name, 'price' => 500, 'quantity' => 4, 'type' => 'product', 'product_id' => $product->id]])
        ->call('openReturnModal', $product->id)
        ->set('returnQuantity', 1)
        ->set('returnReason', 'Guest changed mind')
        ->call('submitReturnRequest');

    $ticket = Order::where('is_return', true)->latest('id')->first();
    $someoneWithNoShift = User::factory()->create();

    expect(fn () => (new ReturnConfirmationService())->confirm($ticket, $someoneWithNoShift))->toThrow(Exception::class);

    $order->refresh();
    expect((float) $order->total_amount)->toEqual(2000.0);
});

it('requires a chef (not bartender) shift to confirm a kitchen return', function () {
    ['table' => $table, 'product' => $product, 'waiter' => $waiter, 'order' => $order] = seedReturnScenario('kitchen');

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $table->id)
        ->set('existingItems', [$product->id => ['name' => $product->name, 'price' => 500, 'quantity' => 4, 'type' => 'product', 'product_id' => $product->id]])
        ->call('openReturnModal', $product->id)
        ->set('returnQuantity', 1)
        ->set('returnReason', 'Wrong dish')
        ->call('submitReturnRequest');

    $ticket = Order::where('is_return', true)->latest('id')->first();
    expect($ticket->destination)->toBe('kitchen');

    $bartender = User::factory()->create();
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);
    expect(fn () => (new ReturnConfirmationService())->confirm($ticket, $bartender))->toThrow(Exception::class);

    $chef = User::factory()->create();
    Shift::create(['user_id' => $chef->id, 'type' => 'chef', 'started_at' => now(), 'status' => 'active']);
    (new ReturnConfirmationService())->confirm($ticket, $chef);

    expect($ticket->fresh()->status)->toBe('returned');
});

it('rejects a return without touching the bill or stock, and closes the ticket', function () {
    ['table' => $table, 'product' => $product, 'waiter' => $waiter, 'order' => $order] = seedReturnScenario('bar');

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $table->id)
        ->set('existingItems', [$product->id => ['name' => $product->name, 'price' => 500, 'quantity' => 4, 'type' => 'product', 'product_id' => $product->id]])
        ->call('openReturnModal', $product->id)
        ->set('returnQuantity', 1)
        ->set('returnReason', 'Guest changed mind')
        ->call('submitReturnRequest');

    $ticket = Order::where('is_return', true)->latest('id')->first();
    $bartender = User::factory()->create();
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    (new ReturnConfirmationService())->reject($ticket, $bartender, 'Never came back to the bar');

    expect($ticket->fresh()->status)->toBe('cancelled');
    expect($ticket->fresh()->cancellation_reason)->toBe('Never came back to the bar');

    $order->refresh();
    expect((float) $order->total_amount)->toEqual(2000.0);
});

it('blocks shift end while a return is still pending confirmation', function () {
    ['table' => $table, 'product' => $product, 'waiter' => $waiter, 'order' => $order] = seedReturnScenario('bar');
    $order->update(['status' => 'paid', 'amount_paid' => 2000]); // clear the outstanding-order guard so pending-returns is the only blocker

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $table->id)
        ->set('existingItems', [$product->id => ['name' => $product->name, 'price' => 500, 'quantity' => 4, 'type' => 'product', 'product_id' => $product->id]])
        ->call('openReturnModal', $product->id)
        ->set('returnQuantity', 1)
        ->set('returnReason', 'Guest changed mind')
        ->call('submitReturnRequest');

    expect(fn () => $waiter->endShift())->toThrow(Exception::class);
});
