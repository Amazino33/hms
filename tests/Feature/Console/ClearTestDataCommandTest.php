<?php

use App\Models\CashDrop;
use App\Models\Category;
use App\Models\CountSession;
use App\Models\FridgeRestockMark;
use App\Models\IngredientTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\KioskDevice;
use App\Models\KioskRegistrationCode;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\CountSessionService;
use Illuminate\Support\Facades\DB;
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

    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    $mainStore = WareHouse::firstOrCreate(['id' => 1], ['name' => 'Main Store', 'is_active' => 1]);
    $inventoryItem = InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

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

    KioskRegistrationCode::create([
        'code_hash' => bcrypt('ABC123'),
        'created_by' => $waiter->id,
        'expires_at' => now()->addMinutes(10),
    ]);

    $countSession = (new CountSessionService())->openSession('bar_handover', $bar->id, $waiter->id, $waiter->id, $receiver->id);

    $stockTransfer = StockTransfer::create([
        'transfer_number' => 'ST-CLEAR-1',
        'from_warehouse_id' => $mainStore->id,
        'to_warehouse_id' => $bar->id,
        'user_id' => $waiter->id,
        'status' => 'pending',
    ]);

    InventoryTransaction::create([
        'product_id' => $product->id,
        'warehouse_id' => $bar->id,
        'type' => 'purchase',
        'quantity' => 10,
        'reference' => 'invoice_INV-1',
        'cost_per_unit' => 100,
        'user_id' => $waiter->id,
    ]);

    $stockAdjustment = StockAdjustment::create([
        'item_type' => 'product',
        'product_id' => $product->id,
        'warehouse_id' => $bar->id,
        'quantity_change' => -2,
        'reason' => 'damage',
        'status' => 'pending',
        'requested_by' => $waiter->id,
    ]);

    $fridgeMark = FridgeRestockMark::create([
        'product_id' => $product->id,
        'warehouse_id' => $bar->id,
        'marked_quantity' => 5,
        'marked_at' => now(),
        'marked_by' => $waiter->id,
    ]);

    DB::table('notifications')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\Test',
        'notifiable_type' => User::class,
        'notifiable_id' => $waiter->id,
        'data' => json_encode(['test' => true]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('sessions')->insert([
        'id' => 'test-session-id',
        'user_id' => $waiter->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => base64_encode('test'),
        'last_activity' => now()->timestamp,
    ]);

    return compact(
        'waiter', 'receiver', 'table', 'category', 'product', 'menuItem',
        'shift', 'order', 'debt', 'device', 'inventoryItem', 'countSession',
        'stockTransfer', 'stockAdjustment', 'fridgeMark',
    );
}

it('clears all transactional data and resets tables to available', function () {
    seedClearTestDataFixtures();

    expect(Order::count())->toBeGreaterThan(0);
    expect(Shift::count())->toBeGreaterThan(0);
    expect(CashDrop::count())->toBeGreaterThan(0);
    expect(StaffDebt::count())->toBeGreaterThan(0);
    expect(Activity::count())->toBeGreaterThan(0);
    expect(CountSession::count())->toBeGreaterThan(0);
    expect(StockTransfer::count())->toBeGreaterThan(0);
    expect(InventoryTransaction::count())->toBeGreaterThan(0);
    expect(StockAdjustment::count())->toBeGreaterThan(0);
    expect(KioskRegistrationCode::count())->toBeGreaterThan(0);
    expect(FridgeRestockMark::count())->toBeGreaterThan(0);
    expect(DB::table('notifications')->count())->toBeGreaterThan(0);
    expect(DB::table('sessions')->count())->toBeGreaterThan(0);

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
    expect(CountSession::count())->toBe(0);
    expect(\App\Models\CountSessionItem::count())->toBe(0);
    expect(StockTransfer::count())->toBe(0);
    expect(InventoryTransaction::count())->toBe(0);
    expect(IngredientTransaction::count())->toBe(0);
    expect(StockAdjustment::count())->toBe(0);
    expect(KioskRegistrationCode::count())->toBe(0);
    expect(FridgeRestockMark::count())->toBe(0);
    expect(DB::table('notifications')->count())->toBe(0);
    expect(DB::table('sessions')->count())->toBe(0);

    expect(TableModel::where('status', '!=', 'available')->count())->toBe(0);
});

it('never touches users, products, current stock quantities, categories, menu items, roles, or kiosk device registrations', function () {
    [
        'waiter' => $waiter, 'product' => $product, 'category' => $category,
        'menuItem' => $menuItem, 'device' => $device, 'inventoryItem' => $inventoryItem,
    ] = seedClearTestDataFixtures();
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

    // Current stock quantities are configuration, not history — the count
    // session that touched this product got wiped, but the actual quantity
    // on the shelf right now must survive untouched.
    expect(InventoryItem::find($inventoryItem->id)?->quantity)->toBe(24);
});

it('does nothing without --force when the confirmation is declined', function () {
    seedClearTestDataFixtures();

    $this->artisan('app:clear-test-data')
        ->expectsConfirmation('Are you sure you want to continue?', 'no')
        ->assertExitCode(0);

    expect(Order::count())->toBeGreaterThan(0);
    expect(Shift::count())->toBeGreaterThan(0);
});
