<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\WareHouse;
use Livewire\Livewire;

function seedFastMarkPaidOrder(User $waiter, string $status = 'served', float $totalAmount = 1000, float $amountPaid = 0, string $destination = 'bar'): Order
{
    $table = TableModel::where('name', 'Table 1')->first()
        ?? TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'occupied', 'location' => 'Main']);

    return Order::create([
        'order_number' => 'ORD-TEST-' . uniqid(),
        'table_id' => $table->id,
        'user_id' => $waiter->id,
        'status' => $status,
        'destination' => $destination,
        'total_amount' => $totalAmount,
        'amount_paid' => $amountPaid,
    ]);
}

it('marks a served order fully paid with the chosen method, using the order own total with no client-supplied amount', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $order = seedFastMarkPaidOrder($waiter, 'served', 1500);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $order->table_id)
        ->call('markPaidFast', 'cash');

    $order->refresh();
    expect($order->status)->toBe('paid');
    expect((float) $order->amount_paid)->toEqual(1500.0);

    $payment = OrderPayment::where('order_id', $order->id)->first();
    expect($payment)->not->toBeNull();
    expect((float) $payment->amount)->toEqual(1500.0);
    expect($payment->method)->toBe('cash');
    expect($payment->user_id)->toBe($waiter->id);
});

it('only charges the remaining outstanding balance on a partially-paid order, not the full total again', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $order = seedFastMarkPaidOrder($waiter, 'served', 1000, 400);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $order->table_id)
        ->call('markPaidFast', 'pos');

    $order->refresh();
    expect((float) $order->amount_paid)->toEqual(1000.0);

    $payment = OrderPayment::where('order_id', $order->id)->first();
    expect((float) $payment->amount)->toEqual(600.0); // only the remaining 600, not 1000
    expect($payment->method)->toBe('pos');
});

it('pays off every served order at the table in one action, e.g. a split bar+kitchen bill', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $table = TableModel::create(['name' => 'Table 2', 'capacity' => 4, 'status' => 'occupied', 'location' => 'Main']);
    $barOrder = Order::create(['order_number' => 'ORD-BAR-' . uniqid(), 'table_id' => $table->id, 'user_id' => $waiter->id, 'status' => 'served', 'destination' => 'bar', 'total_amount' => 500, 'amount_paid' => 0]);
    $kitchenOrder = Order::create(['order_number' => 'ORD-KIT-' . uniqid(), 'table_id' => $table->id, 'user_id' => $waiter->id, 'status' => 'served', 'destination' => 'kitchen', 'total_amount' => 700, 'amount_paid' => 0]);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $table->id)
        ->call('markPaidFast', 'transfer');

    expect($barOrder->fresh()->status)->toBe('paid');
    expect($kitchenOrder->fresh()->status)->toBe('paid');
    expect(OrderPayment::where('order_id', $barOrder->id)->sum('amount'))->toEqual(500.0);
    expect(OrderPayment::where('order_id', $kitchenOrder->id)->sum('amount'))->toEqual(700.0);
});

it('refuses to mark paid while there are unsent cart items', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $order = seedFastMarkPaidOrder($waiter, 'served', 1000);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $order->table_id)
        ->set('existingItems', ['99' => ['name' => 'Extra Beer', 'price' => 500, 'quantity' => 1, 'type' => 'product']])
        ->call('markPaidFast', 'cash');

    expect($order->fresh()->status)->toBe('served');
    expect(OrderPayment::where('order_id', $order->id)->exists())->toBeFalse();
});

it('refuses to mark paid while an order at the table is still cooking or not yet confirmed served', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $order = seedFastMarkPaidOrder($waiter, 'ready', 1000);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $order->table_id)
        ->call('markPaidFast', 'cash');

    expect($order->fresh()->status)->toBe('ready');
    expect(OrderPayment::count())->toBe(0);
});

it('does nothing when there are no served orders to pay for', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $table = TableModel::create(['name' => 'Table 3', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $table->id)
        ->call('markPaidFast', 'cash');

    expect(OrderPayment::count())->toBe(0);
});

it('requires an active shift to mark paid', function () {
    $waiter = User::factory()->create();
    $order = seedFastMarkPaidOrder($waiter, 'served', 1000);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $order->table_id)
        ->call('markPaidFast', 'cash');

    expect($order->fresh()->status)->toBe('served');
    expect(OrderPayment::count())->toBe(0);
});

it('rejects an invalid payment method silently rather than recording anything', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $order = seedFastMarkPaidOrder($waiter, 'served', 1000);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', (string) $order->table_id)
        ->call('markPaidFast', 'bitcoin');

    expect($order->fresh()->status)->toBe('served');
    expect(OrderPayment::count())->toBe(0);
});
