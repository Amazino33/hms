<?php

use App\Filament\Pages\FloorPlan;
use App\Models\Order;
use App\Models\Shift;
use App\Models\Table as TableModel;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Phase 0B — the floor plan's JSON "add item" endpoint added order lines
 * with no stock movement, no shift check and no notification. No screen
 * ever called it (the floor plan sends waiters to the POS instead), so it
 * was removed rather than rewired. See
 * docs/audits/floorplan-add-item-verification.md.
 */
it('no longer exposes the floor-plan add-item endpoint or its two JSON siblings', function () {
    $waiter = User::factory()->create();
    $table = TableModel::create(['name' => 'Table FP', 'capacity' => 4, 'status' => 'occupied', 'location' => 'Main']);
    $order = Order::create([
        'order_number' => 'ORD-FP-'.uniqid(), 'table_id' => $table->id, 'user_id' => $waiter->id,
        'status' => 'pending', 'destination' => 'bar', 'total_amount' => 0,
    ]);

    $this->actingAs($waiter)
        ->postJson('/admin/floor-plan/add-item', ['order_id' => $order->id, 'item_type' => 'product', 'item_id' => 1, 'quantity' => 3])
        ->assertNotFound();

    $this->actingAs($waiter)->getJson("/admin/floor-plan/order/{$order->id}")->assertNotFound();
    $this->actingAs($waiter)->getJson('/admin/floor-plan/popular-items')->assertNotFound();

    expect($order->items()->count())->toBe(0);
    expect((float) $order->fresh()->total_amount)->toBe(0.0);
});

it('still sends the floor plan to the standard POS path to add items', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));
    Shift::create(['user_id' => $admin->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    $table = TableModel::create(['name' => 'Table FP2', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    Livewire::actingAs($admin)
        ->test(FloorPlan::class)
        ->assertOk()
        ->assertSee("/admin/pos-page?table_id={$table->id}", false);
});
