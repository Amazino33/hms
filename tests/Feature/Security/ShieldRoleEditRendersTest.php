<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;

/**
 * Filament Shield builds the role-permission matrix by instantiating every
 * registered Page and calling getTitle() on it to label the checkbox
 * (HasLabelResolver::getLocalizedPageLabel). That happens outside any
 * request, so Livewire never boots the component and #[Computed] is never
 * wired up — reading a computed property there falls through to Livewire's
 * __get and throws PropertyNotFoundException.
 *
 * One page doing that takes out /admin/shield/roles/{id}/edit for EVERY
 * role, which is the only UI for granting resource permissions. It failed
 * in production as a bare 500 with no clue pointing at the guilty page.
 *
 * So this renders the edit screen for every seeded role rather than one:
 * the cost is a single test, and it catches the next page that reaches for
 * a computed property in getTitle().
 */
it('renders the shield role edit screen for every role', function () {
    Artisan::call('db:seed', ['--class' => 'ShieldSeeder', '--force' => true]);

    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));

    $roles = Role::orderBy('id')->get();

    expect($roles)->not->toBeEmpty();

    foreach ($roles as $role) {
        $this->actingAs($admin)
            ->get("/admin/shield/roles/{$role->id}/edit")
            ->assertOk();
    }
});

/**
 * The direct unit-level version of the same contract: a Page's title must
 * be readable from a bare instance, with no record bound and no Livewire
 * lifecycle behind it. Anything that throws here breaks Shield.
 */
it('can read every filament page title from an unmounted instance', function () {
    $pages = collect(glob(app_path('Filament/Pages/*.php')))
        ->map(fn ($file) => 'App\\Filament\\Pages\\'.basename($file, '.php'))
        ->filter(fn ($class) => class_exists($class) && is_subclass_of($class, \Filament\Pages\Page::class));

    expect($pages)->not->toBeEmpty();

    foreach ($pages as $class) {
        $title = (new $class)->getTitle();

        expect($title)->toBeString()->not->toBe('');
    }
});
