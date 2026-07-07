<?php

use App\Filament\Pages\QuickInventoryUpdate;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('adds to existing stock and logs a purchase transaction', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

    Livewire::actingAs($admin)
        ->test(QuickInventoryUpdate::class)
        ->set('selectedWarehouseId', $warehouse->id)
        ->callTableAction('add_stock', $product, [
            'quantity' => 10,
            'cost_per_unit' => 400,
            'reference' => 'invoice',
            'reference_number' => 'INV-001',
        ]);

    expect((int) InventoryItem::where('product_id', $product->id)->value('quantity'))->toBe(30);

    $txn = InventoryTransaction::where('product_id', $product->id)->where('type', 'purchase')->first();
    expect($txn)->not->toBeNull();
    expect((float) $txn->quantity)->toEqual(10.0);
    expect($txn->reference)->toBe('invoice_INV-001');
});

it('creates a new inventory row when none exists yet for that warehouse', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    Livewire::actingAs($admin)
        ->test(QuickInventoryUpdate::class)
        ->set('selectedWarehouseId', $warehouse->id)
        ->callTableAction('add_stock', $product, [
            'quantity' => 15,
            'cost_per_unit' => 400,
            'reference' => 'manual',
            'reference_number' => 'PO-1',
        ]);

    expect((int) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->value('quantity'))->toBe(15);
});
