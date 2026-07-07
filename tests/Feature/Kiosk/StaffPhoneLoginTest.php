<?php

use App\Http\Middleware\EnsureTrustedDevice;
use App\Models\Table as TableModel;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\PinAuthService;
use App\Services\TrustedDeviceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('shows the staff login form without requiring any prior session', function () {
    $this->get('/staff/login')->assertStatus(200);
});

it('establishes device trust end to end after a correct password, without ever starting a web session', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-password')]);

    $response = $this->post('/staff/login', [
        'email' => $user->email,
        'password' => 'correct-password',
    ]);

    $response->assertRedirect(route('staff.home'));
    expect(Auth::guard('web')->check())->toBeFalse();

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === EnsureTrustedDevice::COOKIE_NAME);
    expect($cookie)->not->toBeNull();
    expect(TrustedDevice::where('user_id', $user->id)->exists())->toBeTrue();

    $this->withUnencryptedCookie(EnsureTrustedDevice::COOKIE_NAME, $cookie->getValue())
        ->get('/staff')
        ->assertStatus(200);
});

it('rejects the wrong password and creates no trusted device', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-password')]);

    $this->post('/staff/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    expect(TrustedDevice::count())->toBe(0);
});

it('throttles repeated wrong-password attempts against the same account, closing the brute-force gap', function () {
    // This route validates a REAL account password (Auth::guard('web')->
    // validate()) with no throttle before this fix — an unlimited brute
    // force here is a full account-takeover path into the same password
    // used for the admin panel, despite the staff_pin guard being isolated.
    $user = User::factory()->create(['password' => Hash::make('correct-password')]);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/staff/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    $response = $this->post('/staff/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(429);
    expect(TrustedDevice::count())->toBe(0);
});

it('redirects to the staff login form when /staff is visited with no trusted device cookie', function () {
    $this->get('/staff')->assertRedirect(route('staff.login'));
});

it('redirects to the staff login form once the trusted device has been revoked', function () {
    $user = User::factory()->create();
    $service = new TrustedDeviceService();
    ['device' => $device, 'token' => $token] = $service->trust($user);
    $service->revoke($device);

    $this->withUnencryptedCookie(EnsureTrustedDevice::COOKIE_NAME, $token)
        ->get('/staff')
        ->assertRedirect(route('staff.login'));
});

it('unlocks with the trusted device owner\'s own PIN and lands on the staff order route, not the kiosk one', function () {
    $user = User::factory()->create();
    (new PinAuthService())->setPin($user, '5739');
    ['token' => $token] = (new TrustedDeviceService())->trust($user);
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    $this->withUnencryptedCookie(EnsureTrustedDevice::COOKIE_NAME, $token);
    session(['trusted_device_user_id' => $user->id]);

    Livewire::test('kiosk-idle-screen')
        ->call('selectTable', $table->id, $table->name)
        ->call('pressDigit', '5')
        ->call('pressDigit', '7')
        ->call('pressDigit', '3')
        ->call('pressDigit', '9')
        ->assertRedirect(route('staff.order', ['table' => $table->id]));

    expect(Auth::guard('staff_pin')->id())->toBe($user->id);
});

it('refuses a correct PIN belonging to someone other than this trusted device\'s owner', function () {
    $owner = User::factory()->create();
    (new PinAuthService())->setPin($owner, '8462');
    $someoneElse = User::factory()->create();
    (new PinAuthService())->setPin($someoneElse, '5739');

    ['token' => $token] = (new TrustedDeviceService())->trust($owner);
    $table = TableModel::create(['name' => 'Table 1', 'capacity' => 4, 'status' => 'available', 'location' => 'Main']);

    $this->withUnencryptedCookie(EnsureTrustedDevice::COOKIE_NAME, $token);
    session(['trusted_device_user_id' => $owner->id]);

    Livewire::test('kiosk-idle-screen')
        ->call('selectTable', $table->id, $table->name)
        ->call('pressDigit', '5')
        ->call('pressDigit', '7')
        ->call('pressDigit', '3')
        ->call('pressDigit', '9')
        ->assertSet('errorMessage', 'This PIN does not belong to this device\'s owner.');

    expect(Auth::guard('staff_pin')->check())->toBeFalse();
});
