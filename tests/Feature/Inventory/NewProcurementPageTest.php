<?php

use App\Filament\Pages\NewProcurement;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\PagePermission;
use App\Models\Procurement;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('lets a storekeeper add a line and save a procurement through the page', function () {
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));
    PagePermission::firstOrCreate(
        ['page_class' => NewProcurement::class, 'role_name' => 'storekeeper'],
        ['page_class' => NewProcurement::class, 'page_name' => 'Record Procurement', 'role_name' => 'storekeeper']
    );

    $store = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create([
        'name' => 'Star Beer', 'category_id' => $category->id, 'price' => 500,
        'base_unit' => 'bottle', 'purchase_unit_name' => 'crate', 'units_per_purchase_unit' => 12,
        'is_active' => true,
    ]);

    Livewire::actingAs($storekeeper)
        ->test(NewProcurement::class)
        ->call('addProductLine', [
            'product_id' => $product->id,
            'entered_qty' => 2,
            'entered_unit' => 'purchase_unit',
            'line_total_cost' => 12000,
            'display_name' => 'Star Beer',
        ])
        ->assertSet('productLines.0.entered_qty', 2)
        ->call('save')
        ->assertSet('productLines', []);

    expect(Procurement::count())->toBe(1);
    expect((float) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $store->id)->value('quantity'))->toBe(24.0);
});

it('includes each past procurement\'s line-item detail (product, qty, cost) in the recent-procurements markup, ready to expand', function () {
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));
    PagePermission::firstOrCreate(
        ['page_class' => NewProcurement::class, 'role_name' => 'storekeeper'],
        ['page_class' => NewProcurement::class, 'page_name' => 'Record Procurement', 'role_name' => 'storekeeper']
    );

    WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create([
        'name' => 'Star Beer', 'category_id' => $category->id, 'price' => 500,
        'base_unit' => 'bottle', 'purchase_unit_name' => 'crate', 'units_per_purchase_unit' => 12,
        'is_active' => true,
    ]);

    app(App\Services\ProcurementService::class)->commit(
        ['location_id' => WareHouse::first()->id, 'supplier_name' => 'ABC Distributors', 'purchased_at' => '2026-07-11'],
        [['product_id' => $product->id, 'entered_qty' => 2, 'entered_unit' => 'purchase_unit', 'line_total_cost' => 12000]],
        [],
        $storekeeper->id,
    );

    $html = Livewire::actingAs($storekeeper)
        ->test(NewProcurement::class)
        ->call('load')
        ->html();

    expect($html)->toContain('Star Beer');
    expect($html)->toContain('crate');
    expect($html)->toContain('24 bottles');
    expect($html)->toContain('12,000.00');
    // The expand toggle itself: an Alpine-driven detail panel, not a
    // separate page/navigation.
    expect($html)->toContain('x-data="{ open: false }"');
});

it('removes a line from the cart without saving', function () {
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));
    PagePermission::firstOrCreate(
        ['page_class' => NewProcurement::class, 'role_name' => 'storekeeper'],
        ['page_class' => NewProcurement::class, 'page_name' => 'Record Procurement', 'role_name' => 'storekeeper']
    );

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Star Beer', 'category_id' => $category->id, 'price' => 500, 'is_active' => true]);

    Livewire::actingAs($storekeeper)
        ->test(NewProcurement::class)
        ->call('addProductLine', ['product_id' => $product->id, 'entered_qty' => 1, 'entered_unit' => 'base_unit', 'line_total_cost' => 500, 'display_name' => 'Star Beer'])
        ->call('removeProductLine', 0)
        ->assertSet('productLines', []);
});
