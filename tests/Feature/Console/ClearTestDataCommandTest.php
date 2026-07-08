<?php

use App\Models\CashDrop;
use App\Models\Category;
use App\Models\KioskDevice;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\Table as TableModel;
use App\Models\User;
use Spatie\Activitylog\Facades\CausesActivity;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

function seedClearTestDataFixtures(): array
{
    $waiter = User::factory()->create();
    $receiver = User::factory()->create();

    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'occupied', 'location' => 'Main']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $menuItem = MenuItem::create(['name' => 'Jollof', 'sku' => 'JOL-1', 'category_id' => $category->id, 'sale_price' => 2000, 'available_for_sale' => true]);

    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $order = Order::create([
        'order_number' => 'ORD-CLEAR-1',
        'table_id' => $table->id,
        'user_id' => $waiter->id,
        'shift_id' => $shift->id,
        'status' => 'paid',
        'destination' => 'bar',
        'total_amount' => 500,
        'amount_paid' => 500,
    ]);
    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'item_type' => 'product',
        'quantity' => 1,
        'unit_price' => 500,
        'subtotal' => 500,
    ]);
    OrderPayment::create([
        'order_id' => $order->id,
        'amount' => 500,
        'method' => 'cash',
        'user_id' => $waiter->id,
        'shift_id' => $shift->id,
        'paid_at' => now(),
    ]);

    CashDrop::create([
        'waiter_id' => $waiter->id,
        'received_by' => $receiver->id,
        'shift_id' => $shift->id,
        'declared_amount' => 100,
        'status' => 'pending',
    ]);

    $debt = StaffDebt::create([
        'user_id' => $waiter->id,
        'shift_id' => $shift->id,
        'order_id' => $order->id,
        'amount' => 50,
        'reason' => 'unpaid_order_conversion',
        'status' => 'open',
        'created_by' => $waiter->id,
    ]);

    activity()->log('test activity for clear-test-data');

    $deviceService = new \App\Services\KioskDeviceService();
    ['code' => $code] = $deviceService->generateRegistrationCode($waiter);
    ['device' => $device] = $deviceService->registerDevice($code, 'Bar Kiosk 1');

    return compact('waiter', 'receiver', 'table', 'category', 'product', 'menuItem', 'shift', 'order', 'debt', 'device');
}

it('clears all transactional data and resets tables to available', function () {
    seedClearTestDataFixtures();

    expect(Order::count())->toBeGreaterThan(0);
    expect(Shift::count())->toBeGreaterThan(0);
    expect(CashDrop::count())->toBeGreaterThan(0);
    expect(StaffDebt::count())->toBeGreaterThan(0);
    expect(Activity::count())->toBeGreaterThan(0);

    $this->artisan('app:clear-test-data', ['--force' => true])
        ->assertExitCode(0);

    expect(Order::count())->toBe(0);
    expect(OrderItem::count())->toBe(0);
    expect(OrderPayment::count())->toBe(0);
    expect(Shift::count())->toBe(0);
    expect(CashDrop::count())->toBe(0);
    expect(StaffDebt::count())->toBe(0);
    expect(\App\Models\StaffDebtRepayment::count())->toBe(0);
    expect(Activity::count())->toBe(0);

    expect(TableModel::where('status', '!=', 'available')->count())->toBe(0);
});

it('never touches users, products, categories, menu items, roles, or kiosk device registrations', function () {
    ['waiter' => $waiter, 'product' => $product, 'category' => $category, 'menuItem' => $menuItem, 'device' => $device] = seedClearTestDataFixtures();
    Role::firstOrCreate(['name' => 'waiter']);
    $waiter->assignRole('waiter');

    $this->artisan('app:clear-test-data', ['--force' => true])
        ->assertExitCode(0);

    expect(User::find($waiter->id))->not->toBeNull();
    expect(Product::find($product->id))->not->toBeNull();
    expect(Category::find($category->id))->not->toBeNull();
    expect(MenuItem::find($menuItem->id))->not->toBeNull();
    expect(Role::where('name', 'waiter')->exists())->toBeTrue();
    expect($waiter->fresh()->hasRole('waiter'))->toBeTrue();
    expect(KioskDevice::find($device->id))->not->toBeNull();
});

it('does nothing without --force when the confirmation is declined', function () {
    seedClearTestDataFixtures();

    $this->artisan('app:clear-test-data')
        ->expectsConfirmation('Are you sure you want to continue?', 'no')
        ->assertExitCode(0);

    expect(Order::count())->toBeGreaterThan(0);
    expect(Shift::count())->toBeGreaterThan(0);
});
