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
        ->call('pressDigit', '5')
        ->call('pressDigit', '7')
        ->call('pressDigit', '3')
        ->call('pressDigit', '9')
        ->assertRedirect(route('kiosk.order', ['table' => $table->id]));

    expect(Auth::guard('staff_pin')->check())->toBeTrue();
    expect(Auth::guard('staff_pin')->id())->toBe($waiter->id);
});

it('shows an error and does not log in on a wrong pin', function () {
    ['token' => $token] = registerKioskAndGetToken();
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);
    $waiter = User::factory()->create();
    (new PinAuthService())->setPin($waiter, '5739');

    $this->withUnencryptedCookie(EnsureValidKioskDevice::COOKIE_NAME, $token);

    Livewire::test('kiosk-idle-screen')
        ->call('selectTable', $table->id, $table->name)
        ->call('pressDigit', '1')
        ->call('pressDigit', '1')
        ->call('pressDigit', '1')
        ->call('pressDigit', '1')
        ->assertSet('errorMessage', 'Incorrect PIN.');

    expect(Auth::guard('staff_pin')->check())->toBeFalse();
});
