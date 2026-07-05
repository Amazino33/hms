<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('stamps shift_id on orders created via checkout (send to kitchen)', function () {
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'started_at' => now(), 'status' => 'active']);
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'chef', 'started_at' => now(), 'status' => 'active']);

    $category = Category::create(['name' => 'Food', 'type' => 'food']);
    $rice = Product::create(['name' => 'Rice', 'price' => 1000, 'category_id' => $category->id, 'is_active' => true]);

    DB::table('tables')->insert([
        'id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    \App\Models\WareHouse::create(['id' => 5, 'name' => 'Kitchen', 'is_active' => 1]);
    \App\Models\InventoryItem::create(['product_id' => $rice->id, 'warehouse_id' => 5, 'quantity' => 10]);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', 1)
        ->call('checkout', [
            $rice->id => ['name' => $rice->name, 'price' => $rice->price, 'quantity' => 1],
        ]);

    $order = Order::where('table_id', 1)->firstOrFail();
    expect($order->shift_id)->toBe($shift->id);
});

it('refuses to send an order to the kitchen with no active shift', function () {
    $waiter = User::factory()->create();

    $category = Category::create(['name' => 'Food', 'type' => 'food']);
    $rice = Product::create(['name' => 'Rice', 'price' => 1000, 'category_id' => $category->id, 'is_active' => true]);

    DB::table('tables')->insert([
        'id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Livewire::actingAs($waiter)
        ->test('pos')
        ->set('selectedTableId', 1)
        ->call('checkout', [
            $rice->id => ['name' => $rice->name, 'price' => $rice->price, 'quantity' => 1],
        ]);

    expect(Order::where('table_id', 1)->exists())->toBeFalse();
});
