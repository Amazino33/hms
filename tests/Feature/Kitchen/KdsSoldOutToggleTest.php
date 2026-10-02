<?php

use App\Filament\Resources\MenuItems\Pages\EditMenuItem;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\MenuItemAvailabilityLog;
use App\Models\User;
use App\Services\MenuAvailabilityService;
use App\Services\PinAuthService;
use Database\Seeders\ShieldSeeder;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/**
 * Phase 1A — the KDS "Sold out" panel. It flips the same
 * menu_items.available_for_sale the admin form edits, only for the
 * signed-in active cook, and logs every flip append-only.
 */
function soMenu(): array
{
    $food = Category::create(['name' => 'Soups', 'type' => 'food']);
    $mains = Category::create(['name' => 'Mains', 'type' => 'food']);

    return [
        'egusi' => MenuItem::create(['name' => 'Egusi', 'sku' => 'MI-SO-'.uniqid(), 'category_id' => $food->id, 'type' => 'food', 'sale_price' => 3000, 'available_for_sale' => true]),
        'jollof' => MenuItem::create(['name' => 'Jollof Rice', 'sku' => 'MI-SO-'.uniqid(), 'category_id' => $mains->id, 'type' => 'food', 'sale_price' => 4500, 'available_for_sale' => true]),
    ];
}

function soCook(string $pin = '4826', ?string $role = 'chef'): User
{
    $cook = User::factory()->create(['name' => 'Chef Bisi']);
    (new PinAuthService)->setPin($cook, $pin);

    if ($role) {
        $cook->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => $role]));
    }

    return $cook;
}

it('refuses a waiter signed in at the KDS (D13): nothing changes, nothing is logged', function () {
    ['egusi' => $egusi] = soMenu();
    soCook('5512', 'waiter');

    Livewire::test('kds-board')
        ->call('submitPin', '5512')
        ->call('openSoldOutPanel')
        ->call('setAvailability', $egusi->id, false)
        ->assertNotified('Not allowed')
        ->call('resetAllAvailable');

    expect($egusi->fresh()->available_for_sale)->toBeTrue();
    expect(MenuItemAvailabilityLog::count())->toBe(0);
    expect(fn () => (new MenuAvailabilityService)->set($egusi, false, User::factory()->create()))
        ->toThrow(Exception::class, 'Only kitchen staff or a manager');
});

it('lets kitchen staff, supervisors and managers toggle (D13)', function (string $role) {
    ['egusi' => $egusi] = soMenu();

    expect((new MenuAvailabilityService)->set($egusi, false, soCook(['chef' => '6142', 'manager' => '6253', 'admin' => '6374', 'super_admin' => '6485'][$role], $role)))->toBeTrue();
    expect($egusi->fresh()->available_for_sale)->toBeFalse();
    expect(MenuItemAvailabilityLog::count())->toBe(1);
})->with(['chef', 'manager', 'admin', 'super_admin']);

it('flips a dish to sold out with a valid PIN, and logs who did it', function () {
    ['egusi' => $egusi] = soMenu();
    $cook = soCook();

    Livewire::test('kds-board')
        ->call('openSoldOutPanel')
        ->assertSee('Egusi')
        ->assertSee('Soups')
        ->call('submitPin', '4826')
        ->call('setAvailability', $egusi->id, false)
        ->assertSee('Sold out');

    expect($egusi->fresh()->available_for_sale)->toBeFalse();

    $log = MenuItemAvailabilityLog::sole();
    expect($log->menu_item_id)->toBe($egusi->id);
    expect($log->from)->toBeTrue();
    expect($log->to)->toBeFalse();
    expect($log->user_id)->toBe($cook->id);
    expect($log->created_at)->not->toBeNull();
});

it('refuses the toggle server-side with no PIN or a wrong PIN, changing nothing', function () {
    ['egusi' => $egusi] = soMenu();
    soCook('4826');

    // No PIN at all — calling the method directly, as a tampered client would.
    Livewire::test('kds-board')
        ->call('setAvailability', $egusi->id, false)
        ->assertSet('errorMessage', 'Sign in as the active cook before marking anything ready.');

    // Wrong PIN: sign-in fails, so there is still no active cook.
    Livewire::test('kds-board')
        ->call('submitPin', '1111')
        ->assertSet('errorMessage', 'Incorrect PIN.')
        ->call('setAvailability', $egusi->id, false)
        ->call('resetAllAvailable');

    expect(Auth::guard('staff_pin')->check())->toBeFalse();
    expect($egusi->fresh()->available_for_sale)->toBeTrue();
    expect(MenuItemAvailabilityLog::count())->toBe(0);
});

it('resets every sold-out dish to available, logging each one', function () {
    ['egusi' => $egusi, 'jollof' => $jollof] = soMenu();
    $cook = soCook();
    $service = new MenuAvailabilityService;
    $service->set($egusi, false, $cook);
    $service->set($jollof, false, $cook);

    Auth::guard('staff_pin')->login($cook);
    Livewire::test('kds-board')->call('openSoldOutPanel')->call('resetAllAvailable');

    expect($egusi->fresh()->available_for_sale)->toBeTrue();
    expect($jollof->fresh()->available_for_sale)->toBeTrue();
    expect(MenuItemAvailabilityLog::where('to', true)->pluck('menu_item_id')->sort()->values()->all())
        ->toBe(collect([$egusi->id, $jollof->id])->sort()->values()->all());
    expect(MenuItemAvailabilityLog::count())->toBe(4);
});

it('does not log a toggle that changes nothing', function () {
    ['egusi' => $egusi] = soMenu();

    expect((new MenuAvailabilityService)->set($egusi, true, soCook()))->toBeFalse();
    expect(MenuItemAvailabilityLog::count())->toBe(0);
});

it('writes the same column as the admin form: one source of truth', function () {
    ['egusi' => $egusi] = soMenu();
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    // Admin marks it sold out...
    Livewire::actingAs($admin)
        ->test(EditMenuItem::class, ['record' => $egusi->getRouteKey()])
        ->fillForm(['available_for_sale' => false])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($egusi->fresh()->available_for_sale)->toBeFalse();

    // ...the KDS shows that, and flips it back on the very same column...
    Auth::guard('staff_pin')->login(soCook());
    Livewire::test('kds-board')
        ->call('openSoldOutPanel')
        ->assertSeeInOrder(['Egusi', 'Sold out'])
        ->call('setAvailability', $egusi->id, true);
    expect($egusi->fresh()->available_for_sale)->toBeTrue();

    // ...and the admin form reads the KDS's change back. (The KDS switched
    // this test's default guard to staff_pin; a real request starts fresh.)
    Auth::shouldUse('web');
    Livewire::actingAs($admin, 'web')
        ->test(EditMenuItem::class, ['record' => $egusi->getRouteKey()])
        ->assertSchemaStateSet(['available_for_sale' => true]);
});

it('refuses to edit or delete an availability log row', function () {
    ['egusi' => $egusi] = soMenu();
    (new MenuAvailabilityService)->set($egusi, false, soCook());
    $log = MenuItemAvailabilityLog::sole();

    expect(fn () => $log->update(['to' => true]))->toThrow(LogicException::class);
    expect(fn () => $log->delete())->toThrow(LogicException::class);
    expect(MenuItemAvailabilityLog::sole()->to)->toBeFalse();
});
