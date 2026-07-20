<?php

use App\Filament\Resources\ProductDeletionRequests\Pages\ListProductDeletionRequests;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDeletionRequest;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Livewire\Livewire;

it('hides the approve/reject actions from the requester on their own request', function () {
    $this->seed(ShieldSeeder::class);
    $requester = User::factory()->create();
    $requester->assignRole('super_admin');
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    $request = ProductDeletionRequest::create([
        'product_id' => $product->id,
        'reason' => 'Duplicate entry',
        'status' => 'pending',
        'requested_by' => $requester->id,
    ]);

    Livewire::actingAs($requester)
        ->test(ListProductDeletionRequests::class)
        ->assertTableActionHidden('approve', $request)
        ->assertTableActionHidden('reject', $request);
});

it('shows the approve/reject actions to a manager who did not make the request', function () {
    $this->seed(ShieldSeeder::class);
    $requester = User::factory()->create();
    $requester->assignRole('super_admin');
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    $request = ProductDeletionRequest::create([
        'product_id' => $product->id,
        'reason' => 'Duplicate entry',
        'status' => 'pending',
        'requested_by' => $requester->id,
    ]);

    Livewire::actingAs($manager)
        ->test(ListProductDeletionRequests::class)
        ->assertTableActionVisible('approve', $request)
        ->assertTableActionVisible('reject', $request);
});

it('actually soft-deletes the product when approve is clicked through the resource', function () {
    $this->seed(ShieldSeeder::class);
    $requester = User::factory()->create();
    $requester->assignRole('super_admin');
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    $request = ProductDeletionRequest::create([
        'product_id' => $product->id,
        'reason' => 'Duplicate entry',
        'status' => 'pending',
        'requested_by' => $requester->id,
    ]);

    Livewire::actingAs($manager)
        ->test(ListProductDeletionRequests::class)
        ->callTableAction('approve', $request);

    expect($product->fresh()->trashed())->toBeTrue();
    expect($request->fresh()->status)->toBe('approved');
});

it('does not let a role with no ProductDeletionRequest permissions view the list at all', function () {
    $this->seed(ShieldSeeder::class);
    $bartender = User::factory()->create();
    $bartender->assignRole('bartender');

    $response = $this->actingAs($bartender)->get('/admin/product-deletion-requests');

    $response->assertForbidden();
});
