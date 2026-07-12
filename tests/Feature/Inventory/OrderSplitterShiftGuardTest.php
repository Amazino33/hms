<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\OrderSplitter;
use Illuminate\Support\Facades\DB;

function seedShiftGuardWarehouses(): void
{
    WareHouse::create(['id' => 4, 'name' => 'Bar', 'location' => 'Back', 'is_active' => 1]);
    WareHouse::create(['id' => 5, 'name' => 'Kitchen', 'location' => 'Ground', 'is_active' => 1]);
}

it('refuses to create any order when the placing waiter has no active shift', function () {
    seedShiftGuardWarehouses();
    $user = User::factory()->create();
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => 4, 'quantity' => 10]);

    DB::table('tables')->insert(['id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main', 'created_at' => now(), 'updated_at' => now()]);

    $service = new OrderSplitter();

    expect(fn () => $service->handle([$beer->id => ['name' => $beer->name, 'price' => $beer->price, 'quantity' => 1]], 1, $user->id, []))
        ->toThrow(Exception::class);

    expect(\App\Models\Order::count())->toBe(0);
});

it('refuses to create a bar order when there is no active bartender shift', function () {
    seedShiftGuardWarehouses();
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => 4, 'quantity' => 10]);

    DB::table('tables')->insert(['id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main', 'created_at' => now(), 'updated_at' => now()]);

    $service = new OrderSplitter();

    expect(fn () => $service->handle([$beer->id => ['name' => $beer->name, 'price' => $beer->price, 'quantity' => 1]], 1, $waiter->id, []))
        ->toThrow(Exception::class, 'No active bartender session');

    expect(\App\Models\Order::count())->toBe(0);
});

it('refuses to create a kitchen order when there is no active chef shift', function () {
    seedShiftGuardWarehouses();
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $category = Category::create(['name' => 'Food', 'type' => 'food']);
    $rice = Product::create(['name' => 'Rice', 'price' => 1000, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $rice->id, 'warehouse_id' => 5, 'quantity' => 10]);

    DB::table('tables')->insert(['id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main', 'created_at' => now(), 'updated_at' => now()]);

    $service = new OrderSplitter();

    expect(fn () => $service->handle([$rice->id => ['name' => $rice->name, 'price' => $rice->price, 'quantity' => 1]], 1, $waiter->id, []))
        ->toThrow(Exception::class, 'No active chef session');

    expect(\App\Models\Order::count())->toBe(0);
});

it('allows a bar order once waiter and bartender shifts are both active', function () {
    seedShiftGuardWarehouses();
    $waiter = User::factory()->create();
    $bartender = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => 4, 'quantity' => 10]);

    DB::table('tables')->insert(['id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main', 'created_at' => now(), 'updated_at' => now()]);

    $service = new OrderSplitter();
    $orders = $service->handle([$beer->id => ['name' => $beer->name, 'price' => $beer->price, 'quantity' => 1]], 1, $waiter->id, []);

    expect($orders)->toHaveCount(1);
    expect(\App\Models\Order::count())->toBe(1);
});

it('treats a stale bartender shift as if none exists', function () {
    seedShiftGuardWarehouses();
    $waiter = User::factory()->create();
    $bartender = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    Shift::create([
        'user_id' => $bartender->id, 'type' => 'bartender',
        'started_at' => now()->subHours(Shift::STALE_AFTER_HOURS + 2), 'status' => 'active',
    ]);

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => 4, 'quantity' => 10]);

    DB::table('tables')->insert(['id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main', 'created_at' => now(), 'updated_at' => now()]);

    $service = new OrderSplitter();

    expect(fn () => $service->handle([$beer->id => ['name' => $beer->name, 'price' => $beer->price, 'quantity' => 1]], 1, $waiter->id, []))
        ->toThrow(Exception::class, 'No active bartender session');
});

/**
 * Regression: with no fixed shift schedule, a bartender legitimately still
 * on duty ~22 hours in (an overnight shift not yet handed over) must not
 * have bar orders blocked — that's the exact production incident that also
 * broke the handover screen (MyCountPageTest covers that half).
 */
it('still allows a bar order from a bartender shift running about 22 hours, past the old 20-hour threshold', function () {
    seedShiftGuardWarehouses();
    $waiter = User::factory()->create();
    $bartender = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now()->subHours(22), 'status' => 'active']);

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => 4, 'quantity' => 10]);

    DB::table('tables')->insert(['id' => 1, 'name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main', 'created_at' => now(), 'updated_at' => now()]);

    $service = new OrderSplitter();
    $orders = $service->handle([$beer->id => ['name' => $beer->name, 'price' => $beer->price, 'quantity' => 1]], 1, $waiter->id, []);

    expect($orders)->toHaveCount(1);
});
