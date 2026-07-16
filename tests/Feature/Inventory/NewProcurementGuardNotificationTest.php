<?php

use App\Filament\Pages\NewProcurement;
use App\Models\Category;
use App\Models\PagePermission;
use App\Models\Procurement;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Part of the system-wide notification/silent-failure fix: pins that the
 * storekeeper's "add at least one line" validation guard on
 * NewProcurement::save() reaches the user as a persistent danger
 * notification, and never creates a Procurement record; the corresponding
 * success path sends a success notification.
 */
function actingStorekeeperForProcurement(): User
{
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));
    PagePermission::firstOrCreate(
        ['page_class' => NewProcurement::class, 'role_name' => 'storekeeper'],
        ['page_class' => NewProcurement::class, 'page_name' => 'Record Procurement', 'role_name' => 'storekeeper']
    );

    return $storekeeper;
}

it('blocks saving a procurement with no lines, sending a persistent danger notification and creating nothing', function () {
    $storekeeper = actingStorekeeperForProcurement();

    session()->forget('filament.notifications');

    Livewire::actingAs($storekeeper)
        ->test(NewProcurement::class)
        ->call('save');

    $last = collect(session('filament.notifications', []))->last();

    expect($last)->not->toBeNull();
    expect($last['status'])->toBe('danger');
    expect($last['duration'])->toBe('persistent');
    expect($last['title'])->toBe('Add at least one line before saving');

    expect(Procurement::count())->toBe(0);
});

it('saves a procurement with a success notification once a line is added', function () {
    $storekeeper = actingStorekeeperForProcurement();
    WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Star Beer', 'category_id' => $category->id, 'price' => 500, 'is_active' => true]);

    session()->forget('filament.notifications');

    Livewire::actingAs($storekeeper)
        ->test(NewProcurement::class)
        ->call('addProductLine', [
            'product_id' => $product->id,
            'entered_qty' => 1,
            'entered_unit' => 'base_unit',
            'line_total_cost' => 500,
            'display_name' => 'Star Beer',
        ])
        ->call('save');

    $last = collect(session('filament.notifications', []))->last();

    expect($last)->not->toBeNull();
    expect($last['status'])->toBe('success');
    expect($last['title'])->toContain('saved');

    expect(Procurement::count())->toBe(1);
});
