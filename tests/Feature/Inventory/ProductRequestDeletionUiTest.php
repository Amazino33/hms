<?php

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDeletionRequest;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

it('deletes immediately from the products table when the actor is super_admin, since no one else can review it', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    Livewire::actingAs($admin)
        ->test(ListProducts::class)
        ->callTableAction('request_deletion', $product, ['reason' => 'Duplicate SKU']);

    expect(ProductDeletionRequest::where('product_id', $product->id)->where('status', 'approved')->exists())->toBeTrue();
    expect($product->fresh()->trashed())->toBeTrue();
});

it('deletes immediately from the product edit page when the actor is super_admin', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    Livewire::actingAs($admin)
        ->test(EditProduct::class, ['record' => $product->id])
        ->callAction('request_deletion', ['reason' => 'Duplicate SKU']);

    expect(ProductDeletionRequest::where('product_id', $product->id)->where('status', 'approved')->exists())->toBeTrue();
    expect($product->fresh()->trashed())->toBeTrue();
});

it('still submits a pending deletion request (not immediate) for a non-super_admin role', function () {
    $this->seed(ShieldSeeder::class);
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    // manager has neither ViewAny:Product nor Delete:Product in the seeded
    // set (only super_admin does) — granted explicitly here so this test
    // exercises the ProductsTable action's own branching logic, not
    // today's incidental permission gap.
    $manager->givePermissionTo(
        Permission::firstOrCreate(['name' => 'ViewAny:Product', 'guard_name' => 'web']),
        Permission::firstOrCreate(['name' => 'Delete:Product', 'guard_name' => 'web']),
    );
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    Livewire::actingAs($manager)
        ->test(ListProducts::class)
        ->callTableAction('request_deletion', $product, ['reason' => 'Duplicate SKU']);

    expect(ProductDeletionRequest::where('product_id', $product->id)->where('status', 'pending')->exists())->toBeTrue();
    expect($product->fresh()->trashed())->toBeFalse();
});

it('shows the restore action to super_admin on a trashed product', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $product->delete();

    Livewire::actingAs($admin)
        ->test(ListProducts::class, ['tableFilters' => ['trashed' => ['value' => '1']]])
        ->assertTableActionVisible('restore', $product);
});

it('hides the restore action for a product that is not trashed', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    Livewire::actingAs($admin)
        ->test(ListProducts::class)
        ->assertTableActionHidden('restore', $product);
});
