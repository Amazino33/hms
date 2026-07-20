<?php

use App\Filament\Pages\ManageCompanySettings;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

/**
 * `php artisan down` writes a real file to storage/framework/down — a
 * file the test process shares with every other test in the same run,
 * completely outside RefreshDatabase's reach. Leaving it behind after a
 * failed assertion would make every other HTTP-based test in the suite
 * start failing with 503s. beforeEach/afterEach both force it off no
 * matter what happens in between, so a failure here can never leak into
 * unrelated tests.
 */
beforeEach(function () {
    Artisan::call('up');
});

afterEach(function () {
    Artisan::call('up');
});

it('enables maintenance mode with a generated secret and a started_at timestamp', function () {
    Artisan::call('hms:maintenance-down');

    expect(app()->maintenanceMode()->active())->toBeTrue();

    $company = Company::find(1);
    expect($company->maintenance_secret)->not->toBeEmpty();
    expect($company->maintenance_started_at)->not->toBeNull();
});

it('reuses the existing secret on a second activation instead of rotating it', function () {
    Artisan::call('hms:maintenance-down');
    $firstSecret = Company::find(1)->maintenance_secret;
    Artisan::call('up');

    Artisan::call('hms:maintenance-down');
    $secondSecret = Company::find(1)->maintenance_secret;

    expect($secondSecret)->toBe($firstSecret);
});

it('disables maintenance mode', function () {
    Artisan::call('hms:maintenance-down');
    expect(app()->maintenanceMode()->active())->toBeTrue();

    Artisan::call('hms:maintenance-up');
    expect(app()->maintenanceMode()->active())->toBeFalse();
});

it('serves the custom maintenance page with the configured message to a request with no bypass', function () {
    Company::updateOrCreate(['id' => 1], [
        'name' => 'Test Co',
        'maintenance_message' => 'Custom deploy message for visitors.',
        'maintenance_duration_minutes' => 20,
        'maintenance_started_at' => now(),
    ]);

    Artisan::call('hms:maintenance-down');

    $response = $this->get('/');

    $response->assertStatus(503);
    $response->assertSee('Custom deploy message for visitors.');
    $response->assertSee('Try Again Now');
});

it('lets a request carrying the secret path bypass maintenance mode', function () {
    Artisan::call('hms:maintenance-down');
    $secret = Company::find(1)->maintenance_secret;

    $response = $this->get('/' . $secret);

    // The middleware redirects to the intended destination and plants the
    // bypass cookie — it is never itself a 503.
    $response->assertStatus(302);
    $response->assertCookie('laravel_maintenance');
});

it('grants the admin who enabled it a working bypass cookie without a separate visit to the secret URL', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    Livewire::actingAs($admin)
        ->test(ManageCompanySettings::class)
        ->callAction('enableMaintenance');

    expect(app()->maintenanceMode()->active())->toBeTrue();
    // Cookie::queue() is what a real HTTP response cycle actually ships to
    // the browser (AddQueuedCookiesToResponse attaches it) — Livewire's
    // own component-test response wrapper doesn't carry queued cookies the
    // same way a full HTTP TestResponse does, so this is the reliable way
    // to confirm the action queued it at all.
    expect(\Illuminate\Support\Facades\Cookie::hasQueued('laravel_maintenance'))->toBeTrue();
});

it('shows the maintenance banner and bypass URL on the settings page only while active', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    Livewire::actingAs($admin)
        ->test(ManageCompanySettings::class)
        ->assertDontSee('Maintenance mode is currently ON');

    Artisan::call('hms:maintenance-down');

    Livewire::actingAs($admin)
        ->test(ManageCompanySettings::class)
        ->assertSee('Maintenance mode is currently ON');
});
