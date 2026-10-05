<?php

use App\Http\Middleware\EnsureValidKioskDevice;
use App\Models\Table as TableModel;
use App\Models\User;
use App\Services\KioskDeviceService;
use App\Services\PinAuthService;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

function registerKioskAndGetToken(): array
{
    $admin = User::factory()->create();
    $service = new KioskDeviceService();
    ['code' => $code] = $service->generateRegistrationCode($admin);

    return $service->registerDevice($code, 'Bar Kiosk 1');
}

it('shows the table grid on the idle screen once the device is registered', function () {
    ['token' => $token] = registerKioskAndGetToken();
    TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    $this->withUnencryptedCookie(EnsureValidKioskDevice::COOKIE_NAME, $token)
        ->get('/kiosk')
        ->assertStatus(200)
        ->assertSee('Table 1');
});

it('logs the waiter in via staff_pin and redirects to the order screen on a correct pin', function () {
    ['token' => $token] = registerKioskAndGetToken();
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
    $waiter = User::factory()->create();
    (new PinAuthService())->setPin($waiter, '5739');

    $this->withUnencryptedCookie(EnsureValidKioskDevice::COOKIE_NAME, $token);
    // Livewire::test() bypasses routing/middleware entirely, so the kiosk
    // context session key EnsureValidKioskDevice would normally set on a
    // real request has to be simulated here.
    session(['kiosk_device_id' => 1]);

    Livewire::test('kiosk-idle-screen')
        ->call('selectTable', $table->id, $table->name)
        ->call('submitPin', '5739')
        ->assertRedirect(route('kiosk.order', ['table' => $table->id]));

    expect(Auth::guard('staff_pin')->check())->toBeTrue();
    expect(Auth::guard('staff_pin')->id())->toBe($waiter->id);
});

/**
 * Real production error: closePinPad() (fired by the pad's Cancel button
 * or a tap on the backdrop) nulls selectedTableId with no guard against a
 * submission already being in flight — if that races against submitPin(),
 * it was hitting route('kiosk.order', ['table' => null]) and crashing
 * with a raw UrlGenerationException instead of just failing to log in.
 */
it('fails gracefully instead of crashing when submitPin runs with no table selected', function () {
    ['token' => $token] = registerKioskAndGetToken();
    $waiter = User::factory()->create();
    (new PinAuthService())->setPin($waiter, '5739');

    $this->withUnencryptedCookie(EnsureValidKioskDevice::COOKIE_NAME, $token);
    session(['kiosk_device_id' => 1]);

    // Deliberately never calls selectTable() first — reproduces
    // selectedTableId being null (e.g. raced away by closePinPad())
    // at the exact moment submitPin() runs.
    Livewire::test('kiosk-idle-screen')
        ->call('submitPin', '5739')
        ->assertNoRedirect();

    expect(Auth::guard('staff_pin')->check())->toBeFalse();
});

it('shows the name of whoever is handling an occupied table on the table grid', function () {
    ['token' => $token] = registerKioskAndGetToken();
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'occupied', 'location' => 'Main']);
    $waiter = User::factory()->create(['name' => 'Sifon']);

    \App\Models\Order::create([
        'order_number' => 'ORD-1',
        'table_id' => $table->id,
        'user_id' => $waiter->id,
        'status' => 'pending',
        'destination' => 'kitchen',
        'total_amount' => 1500,
    ]);

    $this->withUnencryptedCookie(EnsureValidKioskDevice::COOKIE_NAME, $token)
        ->get('/kiosk')
        ->assertStatus(200)
        ->assertSee('Sifon');
});

/**
 * Real production report: tables nobody was sitting at showed "Occupied"
 * (and a waiter's name) — from a stored status flag left behind, and from
 * leftover orders worth ₦0 or already settled. Occupied now comes from
 * live orders only.
 */
it('shows a table as Available when only a stale flag or leftover ₦0 / settled orders remain', function () {
    ['token' => $token] = registerKioskAndGetToken();
    $waiter = User::factory()->create(['name' => 'Peace Samuel']);
    $order = fn (TableModel $table, string $status, float $total, float $paid = 0) => \App\Models\Order::create([
        'order_number' => 'ORD-'.uniqid(), 'table_id' => $table->id, 'user_id' => $waiter->id,
        'status' => $status, 'destination' => 'bar', 'total_amount' => $total, 'amount_paid' => $paid,
    ]);

    $flagOnly = TableModel::create(['name' => 'Table 2', 'capacity' => 4, 'status' => 'occupied', 'location' => 'Main']);
    $zero = TableModel::create(['name' => 'Table 10', 'capacity' => 4, 'status' => 'occupied', 'location' => 'Main']);
    $order($zero, 'served', 0);
    $order($zero, 'pending', 0);
    $settled = TableModel::create(['name' => 'Table 13', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
    $order($settled, 'served', 3000, 3000);
    $owing = TableModel::create(['name' => 'Table 24', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
    $order($owing, 'served', 3000, 1000);
    $cooking = TableModel::create(['name' => 'Table 30', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
    $order($cooking, 'preparing', 2500, 2500);
    $reserved = TableModel::create(['name' => 'Table 40', 'capacity' => 4, 'status' => 'reserved', 'location' => 'Main']);

    $tables = TableModel::with('latestActiveOrder.user')->get()->keyBy('name');
    expect($tables['Table 2']->displayStatus())->toBe('available')
        ->and($tables['Table 10']->displayStatus())->toBe('available')
        ->and($tables['Table 13']->displayStatus())->toBe('available')
        ->and($tables['Table 24']->displayStatus())->toBe('occupied')
        ->and($tables['Table 30']->displayStatus())->toBe('occupied')
        ->and($tables['Table 40']->displayStatus())->toBe('reserved')
        ->and($tables['Table 13']->latestActiveOrder)->toBeNull()
        ->and($tables['Table 24']->latestActiveOrder->user->name)->toBe('Peace Samuel');

    $html = $this->withUnencryptedCookie(EnsureValidKioskDevice::COOKIE_NAME, $token)->get('/kiosk')->assertOk()->getContent();
    expect(substr_count($html, 'Peace Samuel'))->toBe(2); // Tables 24 and 30 only
});

it('does not show a name on an available table with no active order', function () {
    ['token' => $token] = registerKioskAndGetToken();
    TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    $this->withUnencryptedCookie(EnsureValidKioskDevice::COOKIE_NAME, $token)
        ->get('/kiosk')
        ->assertStatus(200)
        ->assertDontSee('Sifon');
});

it('prints a table bill straight from the table grid with no PIN login at all', function () {
    \App\Models\Company::create(['name' => 'Tiano Hotels and Suite', 'address' => '44 Marina Road, Eket', 'phone_number' => '+234 814 473 4612']);

    ['token' => $token] = registerKioskAndGetToken();
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'occupied', 'location' => 'Main']);
    $waiter = User::factory()->create(['name' => 'Sifon']);
    $category = \App\Models\Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = \App\Models\Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    $order = \App\Models\Order::create([
        'order_number' => 'ORD-1',
        'table_id' => $table->id,
        'user_id' => $waiter->id,
        'status' => 'pending',
        'destination' => 'bar',
        'total_amount' => 1000,
    ]);
    $order->items()->create([
        'product_id' => $beer->id,
        'product_name' => $beer->name,
        'item_type' => 'product',
        'quantity' => 2,
        'unit_price' => 500,
        'subtotal' => 1000,
    ]);

    $this->withUnencryptedCookie(EnsureValidKioskDevice::COOKIE_NAME, $token);

    // Deliberately no Auth::guard('staff_pin')->login() anywhere in this
    // test — printing a bill must not require the PIN pad at all.
    Livewire::test('kiosk-idle-screen')
        ->call('printTableBill', $table->id)
        ->assertDispatched('print-bill', function ($name, $params) use ($table) {
            // Livewire round-trips dispatch payloads through JSON, so a
            // whole-number float (1000.0) comes back as PHP int 1000 — loose
            // comparison on the total, not strict.
            return $params[0]['tableName'] === $table->name
                && $params[0]['total'] == 1000
                && $params[0]['cashier'] === 'Sifon'
                && $params[0]['items'][0]['name'] === 'Beer'
                && $params[0]['items'][0]['quantity'] === 2
                && $params[0]['company']['name'] === 'Tiano Hotels and Suite'
                && $params[0]['company']['address'] === '44 Marina Road, Eket'
                && $params[0]['company']['phone'] === '+234 814 473 4612';
        });

    expect(Auth::guard('staff_pin')->check())->toBeFalse();
});

it('warns instead of printing when a table has nothing to print', function () {
    ['token' => $token] = registerKioskAndGetToken();
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    $this->withUnencryptedCookie(EnsureValidKioskDevice::COOKIE_NAME, $token);

    Livewire::test('kiosk-idle-screen')
        ->call('printTableBill', $table->id)
        ->assertNotDispatched('print-bill');
});

it('shows an error and does not log in on a wrong pin', function () {
    ['token' => $token] = registerKioskAndGetToken();
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
    $waiter = User::factory()->create();
    (new PinAuthService())->setPin($waiter, '5739');

    $this->withUnencryptedCookie(EnsureValidKioskDevice::COOKIE_NAME, $token);

    Livewire::test('kiosk-idle-screen')
        ->call('selectTable', $table->id, $table->name)
        ->call('submitPin', '1111')
        ->assertSet('errorMessage', 'Incorrect PIN.');

    expect(Auth::guard('staff_pin')->check())->toBeFalse();
});
