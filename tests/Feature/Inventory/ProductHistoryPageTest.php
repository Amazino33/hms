<?php

use App\Filament\Pages\ProductHistory;
use App\Models\Category;
use App\Models\CountSession;
use App\Models\CountSessionItem;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\WareHouse;
use Livewire\Livewire;

it('shows per-warehouse stock, transactions, count-session history, and adjustments for an active product', function () {
    $admin = User::factory()->create();
    $admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);
    InventoryTransaction::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'type' => 'purchase', 'quantity' => 20, 'user_id' => $admin->id]);
    StockAdjustment::create([
        'item_type' => 'product', 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
        'quantity_change' => -2, 'reason' => 'theft_suspected', 'status' => 'approved', 'requested_by' => $admin->id,
    ]);
    $session = CountSession::create(['type' => 'main_store_stocktake', 'warehouse_id' => $warehouse->id, 'status' => 'reviewed', 'opened_by' => $admin->id, 'opened_at' => now()]);
    CountSessionItem::create(['count_session_id' => $session->id, 'item_type' => 'product', 'product_id' => $product->id, 'expected_quantity_at_open' => 20]);

    Livewire::actingAs($admin)
        ->test(ProductHistory::class, ['product_id' => $product->id])
        ->assertSee('Beer')
        ->assertSee('Main Store')
        ->assertSee('Theft Suspected');
});

it('shows a deleted product\'s full history via withTrashed, with a Deleted badge', function () {
    $admin = User::factory()->create();
    $admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Discontinued Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 5]);
    $product->delete();

    expect(Product::find($product->id))->toBeNull();

    Livewire::actingAs($admin)
        ->test(ProductHistory::class, ['product_id' => $product->id])
        ->assertSee('Discontinued Beer')
        ->assertSee('Deleted');
});

it('shows an empty-state message instead of erroring when the product has no history at all', function () {
    $admin = User::factory()->create();
    $admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Brand New Item', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    Livewire::actingAs($admin)
        ->test(ProductHistory::class, ['product_id' => $product->id])
        ->assertSee('Brand New Item')
        ->assertSee('No inventory rows for this product at any warehouse.');
});

it('lets a manager reach Product History once the seeder grants it, and blocks a storekeeper', function () {
    $this->seed(\Database\Seeders\PagePermissionsSeeder::class);

    $manager = User::factory()->create();
    $manager->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'manager']));

    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'storekeeper']));

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Big Legend', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    $this->actingAs($manager)->get("/admin/product-history?product_id={$product->id}")->assertSuccessful();
    $this->actingAs($storekeeper)->get("/admin/product-history?product_id={$product->id}")->assertForbidden();
});
