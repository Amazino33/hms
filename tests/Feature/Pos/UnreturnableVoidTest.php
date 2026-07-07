<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\UnreturnableVoid;
use App\Models\User;
use App\Services\UnreturnableVoidService;

function seedVoidOrder(): array
{
    $order = Order::create([
        'order_number' => 'ORD-' . uniqid(),
        'user_id' => User::factory()->create()->id,
        'status' => 'served',
        'destination' => 'bar',
        'total_amount' => 2000,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_name' => 'Beer',
        'item_type' => 'product',
        'quantity' => 4,
        'unit_price' => 500,
        'subtotal' => 2000,
    ]);

    return compact('order', 'item');
}

it('reduces the order total without reversing any stock, and records a reasoned audit entry', function () {
    ['order' => $order, 'item' => $item] = seedVoidOrder();
    $manager = User::factory()->create();

    $void = (new UnreturnableVoidService())->apply($item, $manager, 'comp', 1, 'Guest goodwill gesture');

    $order->refresh();
    expect((float) $order->total_amount)->toEqual(1500.0);

    $item->refresh();
    expect($item->quantity)->toBe(3);
    expect((float) $item->subtotal)->toEqual(1500.0);

    expect($void->reason_code)->toBe('comp');
    expect((float) $void->amount)->toEqual(500.0);
    expect($void->manager_id)->toBe($manager->id);
    expect(UnreturnableVoid::count())->toBe(1);
});

it('keeps the order_item row intact even when the entire quantity is voided, for a durable audit trail', function () {
    ['order' => $order, 'item' => $item] = seedVoidOrder();
    $manager = User::factory()->create();

    (new UnreturnableVoidService())->apply($item, $manager, 'loss', 4, 'All spilled');

    $order->refresh();
    expect((float) $order->total_amount)->toEqual(0.0);

    $item->refresh();
    expect($item)->not->toBeNull();
    expect($item->quantity)->toBe(0);

    $void = UnreturnableVoid::first();
    expect($void->order_item_id)->toBe($item->id);
});

it('rejects an invalid reason code', function () {
    ['item' => $item] = seedVoidOrder();
    $manager = User::factory()->create();

    expect(fn () => (new UnreturnableVoidService())->apply($item, $manager, 'not-a-real-reason', 1))
        ->toThrow(Exception::class);
});

it('rejects a quantity greater than what is on the item', function () {
    ['item' => $item] = seedVoidOrder();
    $manager = User::factory()->create();

    expect(fn () => (new UnreturnableVoidService())->apply($item, $manager, 'comp', 99))
        ->toThrow(Exception::class);
});

it('rejects a zero or negative quantity', function () {
    ['item' => $item] = seedVoidOrder();
    $manager = User::factory()->create();

    expect(fn () => (new UnreturnableVoidService())->apply($item, $manager, 'comp', 0))
        ->toThrow(Exception::class);
});
