<?php

use App\Filament\Resources\MenuItems\Pages\CreateMenuItem;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'ShieldSeeder', '--force' => true]);
});

/**
 * Only `sku` had a uniqueness check — `name` had none at all, so a
 * duplicate-named menu item (different SKU) silently created with zero
 * warning. This is the concrete gap behind a live report of "duplicated
 * stuff, no error showed up" when adding to the menu.
 */
it('rejects a duplicate menu item name with a visible validation error, not a silent success', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));
    $category = Category::create(['name' => 'Mains', 'type' => 'food']);

    MenuItem::create([
        'name' => 'Jollof Rice',
        'sku' => 'JOL-001',
        'category_id' => $category->id,
        'sale_price' => 2500,
        'available_for_sale' => true,
    ]);

    Livewire::actingAs($admin)
        ->test(CreateMenuItem::class)
        ->fillForm([
            'name' => 'Jollof Rice',
            'sku' => 'JOL-002',
            'category_id' => $category->id,
            'sale_price' => 2500,
        ])
        ->call('create')
        ->assertHasFormErrors(['name']);

    expect(MenuItem::where('name', 'Jollof Rice')->count())->toBe(1);
});

it('still allows creating a menu item with a genuinely new name', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));
    $category = Category::create(['name' => 'Mains', 'type' => 'food']);

    Livewire::actingAs($admin)
        ->test(CreateMenuItem::class)
        ->fillForm([
            'name' => 'Fried Rice',
            'sku' => 'FR-001',
            'category_id' => $category->id,
            'sale_price' => 2200,
            'recipes' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(MenuItem::where('name', 'Fried Rice')->exists())->toBeTrue();
});
