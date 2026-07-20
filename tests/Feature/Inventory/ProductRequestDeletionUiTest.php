<?php

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDeletionRequest;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Livewire\Livewire;

it('submits a pending deletion request from the products table without touching the product', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    Livewire::actingAs($admin)
        ->test(ListProducts::class)
        ->callTableAction('request_deletion', $product, ['reason' => 'Duplicate SKU']);

    expect(ProductDeletionRequest::where('product_id', $product->id)->where('status', 'pending')->exists())->toBeTrue();
    expect($product->fresh()->trashed())->toBeFalse();
});

it('submits a pending deletion request from the product edit page', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    Livewire::actingAs($admin)
        ->test(EditProduct::class, ['record' => $product->id])
        ->callAction('request_deletion', ['reason' => 'Duplicate SKU']);

    expect(ProductDeletionRequest::where('product_id', $product->id)->where('status', 'pending')->exists())->toBeTrue();
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
