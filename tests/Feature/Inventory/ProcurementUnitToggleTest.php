<?php

use App\Filament\Pages\NewProcurement;
use App\Filament\Pages\StorekeeperTransfers;
use App\Models\Category;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Audit finding: the unit toggle was never actually broken — it already
 * reacts correctly to whichever product is selected. It only ever showed
 * "bottle" because products had no purchase_unit_name/units_per_purchase_unit
 * data at all. These tests pin the underlying data contract both the
 * procurement and transfer line entry read from, now that the opening-stock
 * import populates it, plus the markup that makes the pack option
 * conditional in each.
 */
it('includes pack fields for a product that has them, on the procurement page', function () {
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));
    PagePermission::firstOrCreate(
        ['page_class' => NewProcurement::class, 'role_name' => 'storekeeper'],
        ['page_class' => NewProcurement::class, 'page_name' => 'Record Procurement', 'role_name' => 'storekeeper']
    );

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $withPack = Product::create([
        'name' => 'Star Beer', 'category_id' => $category->id, 'price' => 500,
        'base_unit' => 'bottle', 'purchase_unit_name' => 'crate', 'units_per_purchase_unit' => 12, 'is_active' => true,
    ]);
    $withoutPack = Product::create([
        'name' => 'Loose Item', 'category_id' => $category->id, 'price' => 500, 'is_active' => true,
    ]);

    $component = Livewire::actingAs($storekeeper)->test(NewProcurement::class);
    $products = collect($component->instance()->getViewData()['products'])->keyBy('id');

    expect($products[$withPack->id]->purchase_unit_name)->toBe('crate');
    expect($products[$withPack->id]->units_per_purchase_unit)->toBe(12);
    expect($products[$withoutPack->id]->purchase_unit_name)->toBeNull();
    expect($products[$withoutPack->id]->units_per_purchase_unit)->toBeNull();
});

it('includes pack fields for a product that has them, on the transfer page', function () {
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));
    PagePermission::firstOrCreate(
        ['page_class' => StorekeeperTransfers::class, 'role_name' => 'storekeeper'],
        ['page_class' => StorekeeperTransfers::class, 'page_name' => 'Storekeeper Transfers', 'role_name' => 'storekeeper']
    );

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $withPack = Product::create([
        'name' => 'Star Beer', 'category_id' => $category->id, 'price' => 500,
        'base_unit' => 'bottle', 'purchase_unit_name' => 'crate', 'units_per_purchase_unit' => 12, 'is_active' => true,
    ]);
    $withoutPack = Product::create([
        'name' => 'Loose Item', 'category_id' => $category->id, 'price' => 500, 'is_active' => true,
    ]);

    WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);

    $component = Livewire::actingAs($storekeeper)->test(StorekeeperTransfers::class);
    $products = collect($component->instance()->getViewData()['products'])->keyBy('id');

    expect($products[$withPack->id]->purchase_unit_name)->toBe('crate');
    expect($products[$withPack->id]->units_per_purchase_unit)->toBe(12);
    expect($products[$withoutPack->id]->purchase_unit_name)->toBeNull();
});

it('wires the procurement unit toggle to only offer the purchase-unit button when pack data exists', function () {
    // Mobile pass: this is now a tap-target button pair, not a <select>, but
    // the same conditional-on-pack-data guard still governs the second option.
    $view = file_get_contents(resource_path('views/filament/pages/new-procurement.blade.php'));

    expect($view)->toContain('x-if="unitsPerPurchaseUnit"');
    expect($view)->toContain("enteredUnit = 'purchase_unit'");
});

it('wires the transfer line entry to only offer the purchase-unit option when pack data exists', function () {
    $view = file_get_contents(resource_path('views/filament/pages/storekeeper-transfers.blade.php'));

    expect($view)->toContain('item.units_per_purchase_unit');
    expect($view)->toContain('value="purchase_unit"');
});
