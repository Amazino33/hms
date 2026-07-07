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
        ->fillForm([
            'order_item_id' => $item->id,
            'quantity' => 1,
            'reason_code' => 'comp',
            'notes' => 'Goodwill',
        ])
        ->call('apply')
        ->assertHasNoFormErrors();

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
