<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\KioskDeviceService;
use App\Services\PinAuthService;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/**
 * The kiosk touchscreen (1920x1080) gets a dedicated fixed-viewport,
 * touch-first shell — but pos.blade.php is also embedded in the Filament
 * admin Sales page and rendered for staff-phone (trusted device, no
 * kiosk_device_id session key). Both of those must keep rendering the
 * original layout untouched; only an actual registered kiosk device
 * session should see the new shell.
 */
function seedRedesignFixtures(): array
{
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => $bar->id, 'quantity' => 10]);
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'PSB']);

    return compact('beer', 'table');
}

it('renders the touch-first kiosk shell, including Place Order, for an actual kiosk device session', function () {
    $admin = User::factory()->create();
    $deviceService = new KioskDeviceService();
    ['code' => $code] = $deviceService->generateRegistrationCode($admin);
    ['device' => $device] = $deviceService->registerDevice($code, 'Bar Kiosk 1');

    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    ['table' => $table] = seedRedesignFixtures();

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['kiosk_device_id' => $device->id]);

    Livewire::test('pos', ['table_id' => $table->id])
        ->assertSee('Place Order')
        ->assertSee('Select a Table')
        ->assertSee('Pay')
        ->assertSee('Cancel');
});

it('keeps the original layout for the admin Sales page, with no kiosk-only markup', function () {
    $user = User::factory()->create();
    Shift::create(['user_id' => $user->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    ['table' => $table] = seedRedesignFixtures();

    Livewire::actingAs($user)
        ->test('pos', ['table_id' => $table->id])
        ->assertDontSee('Place Order')
        ->assertDontSee('Select a Table')
        ->assertSee('Order');
});

it('keeps the original mobile/desktop layout for staff-phone, which is not a kiosk device', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    ['table' => $table] = seedRedesignFixtures();

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['trusted_device_user_id' => $waiter->id]);

    Livewire::test('pos', ['table_id' => $table->id])
        ->assertDontSee('Place Order')
        ->assertDontSee('Select a Table')
        ->assertSee('Order');
});

it('groups the kiosk table picker by table location', function () {
    $admin = User::factory()->create();
    $deviceService = new KioskDeviceService();
    ['code' => $code] = $deviceService->generateRegistrationCode($admin);
    ['device' => $device] = $deviceService->registerDevice($code, 'Bar Kiosk 1');

    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    ['table' => $table] = seedRedesignFixtures();
    TableModel::create(['name' => 'BB 1', 'capacity' => 2, 'status' => 'available', 'location' => 'BB']);

    Auth::guard('staff_pin')->login($waiter);
    Auth::shouldUse('staff_pin');
    session(['kiosk_device_id' => $device->id]);

    Livewire::test('pos', ['table_id' => $table->id])
        ->assertSee('PSB')
        ->assertSee('BB')
        ->assertSee('Take Away');
});
