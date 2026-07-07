<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\KioskDeviceService;
use App\Services\PinAuthService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * These tests simulate what EnsureValidKioskDevice + EnsureStaffPinAuthenticated
 * already do on the real route (set the kiosk_device request attribute and
 * Auth::shouldUse('staff_pin')) so the reused `pos` component's plain
 * auth()->id() calls can be tested in isolation from Livewire's own
 * middleware-persistence internals.
 */
it('attributes an order placed via the kiosk to the PIN-identified waiter and stamps the kiosk device', function () {
    $admin = User::factory()->create();
    $deviceService = new KioskDeviceService();
    ['code' => $code] = $deviceService->generateRegistrationCode($admin);
    ['device' => $device] = $deviceService->registerDevice($code, 'Bar Kiosk 1');

    $waiter = User::factory()->create();
    (new PinAuthService())->setPin($waiter, '5739');
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);

    // Simulate what the real middleware stack does on /kiosk/order/{table}.
    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['kiosk_device_id' => $device->id]);

    Livewire::test('pos', ['table_id' => $table->id])
        ->call('checkout', [$product->id => ['name' => $product->name, 'price' => $product->price, 'quantity' => 1]]);

    $order = Order::where('table_id', $table->id)->firstOrFail();
    expect($order->user_id)->toBe($waiter->id);
    expect($order->kiosk_device_id)->toBe($device->id);
});

it('does not stamp a kiosk_device_id when there is no kiosk device in the request context', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);
    Shift::create(['user_id' => User::factory()->create()->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);

    $this->actingAs($waiter);

    Livewire::test('pos', ['table_id' => $table->id])
        ->call('checkout', [$product->id => ['name' => $product->name, 'price' => $product->price, 'quantity' => 1]]);

    $order = Order::where('table_id', $table->id)->firstOrFail();
    expect($order->kiosk_device_id)->toBeNull();
});
