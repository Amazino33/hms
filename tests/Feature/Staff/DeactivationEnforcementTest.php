<?php

use App\Http\Middleware\EnsureTrustedDevice;
use App\Models\CountSession;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\BartenderChefShiftService;
use App\Services\PinAuthService;
use App\Services\ReceptionistShiftService;
use App\Services\TrustedDeviceService;
use Database\Seeders\ShieldSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

/**
 * Staff 0A §4 — every enforcement point refuses a deactivated account, and
 * a future left_at (notice period) is refused by none of them.
 */
beforeEach(function () {
    $this->seed(ShieldSeeder::class);
});

function enforcementStaff(string $role, ?\DateTimeInterface $leftAt, string $pin = '7391'): User
{
    $user = User::factory()->create(['password' => Hash::make('secret-pass')]);
    $user->assignRole($role);
    (new PinAuthService)->setPin($user, $pin);
    $user->forceFill(['left_at' => $leftAt])->save();

    return $user->fresh();
}

it('1. blocks panel access in every panel, super admin included', function () {
    $gone = enforcementStaff('super_admin', now()->subHour());

    expect($gone->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
    expect($gone->canAccessPanel(Filament::getPanel('ceo')))->toBeFalse();
});

it('2. blocks the Filament, Fortify and /staff logins', function () {
    $gone = enforcementStaff('manager', now()->subHour());

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(\Filament\Auth\Pages\Login::class)
        ->fillForm(['email' => $gone->email, 'password' => 'secret-pass'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);
    expect(Auth::guard('web')->check())->toBeFalse();

    $this->post('/login', ['email' => $gone->email, 'password' => 'secret-pass'])
        ->assertSessionHasErrors(['email' => 'This account is no longer active.']);
    $this->assertGuest('web');

    $this->post('/staff/login', ['email' => $gone->email, 'password' => 'secret-pass'])
        ->assertSessionHasErrors(['email' => 'This account is no longer active.']);
    expect(TrustedDevice::where('user_id', $gone->id)->exists())->toBeFalse();
});

it('3. signs out a deactivated web session on its next request, panel or not', function () {
    $user = enforcementStaff('manager', null);

    $this->actingAs($user)->get('/admin')->assertOk();

    $user->forceFill(['left_at' => now()->subMinute()])->save();

    $this->actingAs($user)->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    $this->assertGuest('web');

    $this->actingAs($user)->get('/settings/profile')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'This account is no longer active.');
    $this->assertGuest('web');
});

it('3. signs out a deactivated kiosk (staff_pin) session too', function () {
    $user = enforcementStaff('waiter', now()->subMinute());

    $this->actingAs($user, 'staff_pin')->get('/settings/profile')->assertRedirect(route('staff.login'));
    $this->assertGuest('staff_pin');
});

it('4. refuses a deactivated person on the kiosk PIN pad and their trusted phone', function () {
    $user = enforcementStaff('waiter', null);
    ['token' => $token] = (new TrustedDeviceService)->trust($user);

    $user->forceFill(['left_at' => now()->subMinute()])->save();

    expect((new PinAuthService)->attempt('7391', 'kiosk-1'))->toBeNull();
    expect((new TrustedDeviceService)->resolveToken($token))->toBeNull();

    $this->withUnencryptedCookie(EnsureTrustedDevice::COOKIE_NAME, $token)
        ->get('/staff')
        ->assertRedirect(route('staff.login'));
});

it('5. refuses a deactivated person PIN-approving for someone else, and counts it as a failure', function () {
    enforcementStaff('manager', now()->subMinute(), '8642');

    $service = new PinAuthService;

    // The guest-credit approval and handover seals resolve the approver
    // through attempt() with their own throttle keys.
    foreach (range(1, 5) as $i) {
        expect($service->attempt('8642', 'guest-credit:till'))->toBeNull();
    }

    expect(fn () => $service->attempt('8642', 'guest-credit:till'))->toThrow(\App\Exceptions\PinLockedException::class);
});

it('6. refuses every way of starting a shift', function () {
    $waiter = enforcementStaff('waiter', now()->subMinute());
    expect(fn () => $waiter->startShift())->toThrow(\Exception::class, 'no longer active');

    $receptionist = enforcementStaff('receptionist', now()->subMinute(), '2580');
    expect(fn () => app(ReceptionistShiftService::class)->startShift($receptionist, 0))
        ->toThrow(\Exception::class, 'no longer active');

    $bartender = enforcementStaff('bartender', now()->subMinute(), '1470');
    expect(fn () => app(BartenderChefShiftService::class)->startOpeningShift($bartender, 'bartender', new CountSession))
        ->toThrow(\Exception::class, 'no longer active');

    expect($waiter->shifts()->exists())->toBeFalse();
    expect($receptionist->shifts()->exists())->toBeFalse();
    expect($bartender->shifts()->exists())->toBeFalse();
});

it('8. lets a password reset go through but still refuses the login', function () {
    $gone = enforcementStaff('manager', now()->subHour());
    $token = Password::broker()->createToken($gone);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $gone->email,
        'password' => 'brand-new-pass',
        'password_confirmation' => 'brand-new-pass',
    ]);

    expect(Hash::check('brand-new-pass', $gone->fresh()->password))->toBeTrue();
    $this->assertGuest('web');

    $this->post('/login', ['email' => $gone->email, 'password' => 'brand-new-pass'])
        ->assertSessionHasErrors(['email' => 'This account is no longer active.']);
    $this->assertGuest('web');
});

it('lets someone working out their notice period through every point until the date', function () {
    $leaving = enforcementStaff('waiter', now()->addDay());

    expect($leaving->isDeactivated())->toBeFalse();
    expect($leaving->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
    expect((new PinAuthService)->attempt('7391', 'kiosk-2')?->id)->toBe($leaving->id);

    $this->post('/login', ['email' => $leaving->email, 'password' => 'secret-pass'])->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($leaving, 'web');

    $this->actingAs($leaving)->get('/admin')->assertOk();

    expect($leaving->startShift()->status)->toBe('active');
});
