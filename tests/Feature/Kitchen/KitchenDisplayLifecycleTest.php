<?php

use App\Filament\Pages\BarDisplay;
use App\Filament\Pages\KitchenDisplay;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\ServedConfirmationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * End-to-end walk of the kitchen display's real lifecycle
 * (pending -> ready -> served -> paid), written because this had never
 * actually been exercised by a test before. Also pins the fixes for the
 * three bugs found while auditing it ahead of the hotel-module build:
 * missing `user` eager load (N+1), stale cache after markAsReady(), and
 * markAsReady() having no state/destination guard.
 *
 * Acting user is always super_admin (matching BarDisplayFridgeRestockTest's
 * convention) — canAccess() blocks the page mount entirely for a role/
 * PagePermission-less user, which isn't what these tests are about; the
 * chef/bartender users below are only used for order/shift attribution.
 */
function seedKitchenOrder(): array
{
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'occupied', 'location' => 'Main']);
    $category = Category::create(['name' => 'Food', 'type' => 'food']);
    $product = Product::create(['name' => 'Jollof Rice', 'price' => 1500, 'category_id' => $category->id, 'is_active' => true]);

    $chef = User::factory()->create();
    Shift::create(['user_id' => $chef->id, 'type' => 'chef', 'started_at' => now(), 'status' => 'active']);

    $order = Order::create([
        'order_number' => 'ORD-' . uniqid(),
        'table_id' => $table->id,
        'user_id' => $chef->id,
        'status' => 'pending',
        'destination' => 'kitchen',
        'total_amount' => 1500,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'item_type' => 'product',
        'quantity' => 1,
        'unit_price' => 1500,
        'subtotal' => 1500,
    ]);

    return compact('table', 'product', 'chef', 'order');
}

function actingAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    return $admin;
}

it('walks a kitchen order through the real pending -> ready -> served -> paid lifecycle', function () {
    ['order' => $order, 'chef' => $chef] = seedKitchenOrder();
    $admin = actingAdmin();

    $component = Livewire::actingAs($admin)->test(KitchenDisplay::class);
    $pending = collect($component->instance()->getViewData()['orders']);
    expect($pending->pluck('id'))->toContain($order->id);

    $component->call('markAsReady', $order->id);
    expect($order->fresh()->status)->toBe('ready');

    (new ServedConfirmationService())->confirm($order->fresh(), $chef);
    expect($order->fresh()->status)->toBe('served');

    $order->fresh()->update(['status' => 'paid']);

    $afterPaid = collect(Livewire::actingAs($admin)->test(KitchenDisplay::class)->instance()->getViewData()['recentHistory']);
    expect($afterPaid->pluck('id'))->toContain($order->id);
});

it('eager loads the order user so the display never N+1s rendering "Waiter: {name}"', function () {
    ['order' => $order] = seedKitchenOrder();
    $admin = actingAdmin();

    $orders = Livewire::actingAs($admin)->test(KitchenDisplay::class)->instance()->getViewData()['orders'];

    expect($orders->first()->relationLoaded('user'))->toBeTrue();
});

it('invalidates the active-orders and recent-history cache immediately when a kitchen order is marked ready', function () {
    ['order' => $order] = seedKitchenOrder();
    $admin = actingAdmin();

    $component = Livewire::actingAs($admin)->test(KitchenDisplay::class);
    // Warm the caches, matching a real page load before the tap.
    $component->instance()->getViewData();

    $component->call('markAsReady', $order->id);

    $fresh = $component->instance()->getViewData();
    expect(collect($fresh['orders'])->pluck('id'))->not->toContain($order->id);
    expect(collect($fresh['recentHistory'])->pluck('id'))->toContain($order->id);
});

it('refuses to mark a bar-destination order ready from the kitchen display', function () {
    $table = TableModel::create(['name' => 'Table 2', 'capacity' => 4, 'status' => 'occupied', 'location' => 'Main']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => 4, 'quantity' => 10]);

    $chef = User::factory()->create();
    $barOrder = Order::create([
        'order_number' => 'ORD-' . uniqid(), 'table_id' => $table->id, 'user_id' => $chef->id,
        'status' => 'pending', 'destination' => 'bar', 'total_amount' => 500,
    ]);
    OrderItem::create([
        'order_id' => $barOrder->id, 'product_id' => $product->id, 'product_name' => $product->name,
        'item_type' => 'product', 'quantity' => 1, 'unit_price' => 500, 'subtotal' => 500,
    ]);

    $admin = actingAdmin();

    expect(fn () => Livewire::actingAs($admin)->test(KitchenDisplay::class)->instance()->markAsReady($barOrder->id))
        ->toThrow(ModelNotFoundException::class);
});

it('refuses to re-mark an already-ready kitchen order as ready again', function () {
    ['order' => $order] = seedKitchenOrder();
    $admin = actingAdmin();

    $component = Livewire::actingAs($admin)->test(KitchenDisplay::class);
    $component->call('markAsReady', $order->id);
    expect($order->fresh()->status)->toBe('ready');

    expect(fn () => $component->instance()->markAsReady($order->id))
        ->toThrow(ModelNotFoundException::class);
});

it('matches the same three fixes on the bar display', function () {
    $table = TableModel::create(['name' => 'Table 3', 'capacity' => 4, 'status' => 'occupied', 'location' => 'Main']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Star Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => 4, 'quantity' => 10]);

    $bartender = User::factory()->create();
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $order = Order::create([
        'order_number' => 'ORD-' . uniqid(), 'table_id' => $table->id, 'user_id' => $bartender->id,
        'status' => 'pending', 'destination' => 'bar', 'total_amount' => 500,
    ]);
    OrderItem::create([
        'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name,
        'item_type' => 'product', 'quantity' => 1, 'unit_price' => 500, 'subtotal' => 500,
    ]);

    $admin = actingAdmin();

    $component = Livewire::actingAs($admin)->test(BarDisplay::class);
    $orders = $component->instance()->getViewData()['orders'];
    expect($orders->first()->relationLoaded('user'))->toBeTrue();

    $component->call('markAsReady', $order->id);
    $fresh = $component->instance()->getViewData();
    expect(collect($fresh['orders'])->pluck('id'))->not->toContain($order->id);
    expect(collect($fresh['recentHistory'])->pluck('id'))->toContain($order->id);

    expect(fn () => $component->instance()->markAsReady($order->id))
        ->toThrow(ModelNotFoundException::class);
});
