<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use Database\Seeders\ShieldSeeder;

it('renders the product edit page with a read-only inventory relation manager', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 42]);

    $response = $this->actingAs($admin)->get("/admin/products/{$product->id}/edit");

    $response->assertStatus(200);
    $response->assertSee('42');
    // No inline-editable stock input and no "Add to Warehouse" create action.
    $response->assertDontSee('Add to Warehouse');
});
