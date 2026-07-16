<?php

use App\Filament\Pages\VoidOrderItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\UnreturnableVoid;
use App\Models\User;
use Livewire\Livewire;

it('lets a super_admin void an order item through the page', function () {
    $manager = User::factory()->create();
    $manager->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $order = Order::create(['order_number' => 'ORD-' . uniqid(), 'user_id' => User::factory()->create()->id, 'status' => 'served', 'destination' => 'bar', 'total_amount' => 1000]);
    $item = OrderItem::create(['order_id' => $order->id, 'product_name' => 'Beer', 'item_type' => 'product', 'quantity' => 2, 'unit_price' => 500, 'subtotal' => 1000]);

    Livewire::actingAs($manager)
        ->test(VoidOrderItem::class)
        ->call('selectItem', $item->id)
        ->set('quantity', 1)
        ->set('reasonCode', 'comp')
        ->set('notes', 'Goodwill')
        ->call('apply');

    expect(UnreturnableVoid::count())->toBe(1);
    expect((float) $order->fresh()->total_amount)->toEqual(500.0);
});

it('blocks a plain waiter from the void order item page by default', function () {
    $waiter = User::factory()->create();
    $waiter->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'waiter']));

    $this->actingAs($waiter)
        ->get('/admin/void-order-item')
        ->assertStatus(403);
});

it('finds an order item by product-name search', function () {
    $manager = User::factory()->create();
    $manager->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $order = Order::create(['order_number' => 'ORD-' . uniqid(), 'user_id' => User::factory()->create()->id, 'status' => 'served', 'destination' => 'bar', 'total_amount' => 1000]);
    $item = OrderItem::create(['order_id' => $order->id, 'product_name' => 'Heineken', 'item_type' => 'product', 'quantity' => 2, 'unit_price' => 500, 'subtotal' => 1000]);

    $results = Livewire::actingAs($manager)
        ->test(VoidOrderItem::class)
        ->set('search', 'heine')
        ->instance()
        ->searchResults();

    expect($results)->toHaveCount(1);
    expect($results[0]['id'])->toBe($item->id);
});

it('refuses to void without choosing a reason first, applying nothing', function () {
    $manager = User::factory()->create();
    $manager->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $order = Order::create(['order_number' => 'ORD-' . uniqid(), 'user_id' => User::factory()->create()->id, 'status' => 'served', 'destination' => 'bar', 'total_amount' => 1000]);
    $item = OrderItem::create(['order_id' => $order->id, 'product_name' => 'Beer', 'item_type' => 'product', 'quantity' => 2, 'unit_price' => 500, 'subtotal' => 1000]);

    Livewire::actingAs($manager)
        ->test(VoidOrderItem::class)
        ->call('selectItem', $item->id)
        ->call('apply');

    expect(UnreturnableVoid::count())->toBe(0);
    expect((float) $order->fresh()->total_amount)->toEqual(1000.0);
});

it('rejects an invalid reason code even if one is somehow set client-side', function () {
    $manager = User::factory()->create();
    $manager->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $order = Order::create(['order_number' => 'ORD-' . uniqid(), 'user_id' => User::factory()->create()->id, 'status' => 'served', 'destination' => 'bar', 'total_amount' => 1000]);
    $item = OrderItem::create(['order_id' => $order->id, 'product_name' => 'Beer', 'item_type' => 'product', 'quantity' => 2, 'unit_price' => 500, 'subtotal' => 1000]);

    Livewire::actingAs($manager)
        ->test(VoidOrderItem::class)
        ->call('selectItem', $item->id)
        ->set('reasonCode', 'not-a-real-reason')
        ->call('apply');

    expect(UnreturnableVoid::count())->toBe(0);
});
