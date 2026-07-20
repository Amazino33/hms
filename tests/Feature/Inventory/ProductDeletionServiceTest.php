<?php

use App\Models\Category;
use App\Models\CountSession;
use App\Models\CountSessionItem;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\ProductDeletionService;
use Database\Seeders\ShieldSeeder;

it('does not touch the product when a request is created — only pending', function () {
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    $requester = User::factory()->create();

    $service = new ProductDeletionService;
    $request = $service->request($product, 'Duplicate entry', $requester->id);

    expect($request->status)->toBe('pending');
    expect(Product::find($product->id))->not->toBeNull();
    expect($product->fresh()->trashed())->toBeFalse();
});

it('refuses a second pending request for the same product', function () {
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $requester = User::factory()->create();

    $service = new ProductDeletionService;
    $service->request($product, 'First reason', $requester->id);

    expect(fn () => $service->request($product, 'Second reason', $requester->id))->toThrow(Exception::class);
});

it('soft-deletes the product on approval, preserving every related record', function () {
    test()->seed(ShieldSeeder::class);
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $inventory = InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);
    $txn = InventoryTransaction::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'type' => 'purchase', 'quantity' => 20, 'user_id' => $requester = User::factory()->create()->id]);
    $adjustment = StockAdjustment::create([
        'item_type' => 'product', 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
        'quantity_change' => -2, 'reason' => 'theft_suspected', 'status' => 'approved', 'requested_by' => $requester,
    ]);
    $session = CountSession::create(['type' => 'main_store_stocktake', 'warehouse_id' => $warehouse->id, 'status' => 'reviewed', 'opened_by' => $requester, 'opened_at' => now()]);
    $item = CountSessionItem::create(['count_session_id' => $session->id, 'item_type' => 'product', 'product_id' => $product->id, 'expected_quantity_at_open' => 20]);

    $requesterUser = User::factory()->create();
    $approver = User::factory()->create();
    $approver->assignRole('manager');

    $service = new ProductDeletionService;
    $request = $service->request($product, 'Discontinued', $requesterUser->id);
    $service->approve($request, $approver);

    expect($product->fresh()->trashed())->toBeTrue();
    expect(Product::find($product->id))->toBeNull();
    expect(Product::withTrashed()->find($product->id))->not->toBeNull();

    // Nothing cascaded — every related record is exactly as it was.
    expect(InventoryItem::find($inventory->id))->not->toBeNull();
    expect(InventoryTransaction::find($txn->id))->not->toBeNull();
    expect(StockAdjustment::find($adjustment->id))->not->toBeNull();
    expect(CountSessionItem::find($item->id))->not->toBeNull();

    expect($request->fresh()->status)->toBe('approved');
    expect($request->fresh()->reviewed_by)->toBe($approver->id);
});

it('refuses to let the requester approve their own deletion request, even for super_admin', function () {
    test()->seed(ShieldSeeder::class);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $service = new ProductDeletionService;
    $request = $service->request($product, 'Duplicate', $admin->id);

    expect(fn () => $service->approve($request, $admin))->toThrow(Exception::class);
    expect($product->fresh()->trashed())->toBeFalse();
    expect($request->fresh()->status)->toBe('pending');
});

it('refuses to let a peer without Update:ProductDeletionRequest approve', function () {
    test()->seed(ShieldSeeder::class);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    $requester = User::factory()->create();
    $requester->assignRole('super_admin');
    $peer = User::factory()->create();
    $peer->assignRole('bartender');

    $service = new ProductDeletionService;
    $request = $service->request($product, 'Duplicate', $requester->id);

    expect(fn () => $service->approve($request, $peer))->toThrow(Exception::class);
    expect(fn () => $service->reject($request->fresh(), $peer, 'no'))->toThrow(Exception::class);
    expect($product->fresh()->trashed())->toBeFalse();
});

it('rejects a request without touching the product', function () {
    test()->seed(ShieldSeeder::class);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    $requester = User::factory()->create();
    $reviewer = User::factory()->create();
    $reviewer->assignRole('manager');

    $service = new ProductDeletionService;
    $request = $service->request($product, 'Duplicate', $requester->id);
    $service->reject($request, $reviewer, 'Still actively sold');

    expect($product->fresh()->trashed())->toBeFalse();
    expect($request->fresh()->status)->toBe('rejected');
    expect($request->fresh()->rejection_reason)->toBe('Still actively sold');
});

it('refuses to approve or reject a request that is not pending', function () {
    test()->seed(ShieldSeeder::class);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $approver->assignRole('manager');
    $another = User::factory()->create();
    $another->assignRole('manager');

    $service = new ProductDeletionService;
    $request = $service->request($product, 'Duplicate', $requester->id);
    $service->approve($request, $approver);

    expect(fn () => $service->approve($request->fresh(), $another))->toThrow(Exception::class);
    expect(fn () => $service->reject($request->fresh(), $another, 'too late'))->toThrow(Exception::class);
});

it('restores a soft-deleted product for super_admin only', function () {
    test()->seed(ShieldSeeder::class);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $product->delete();

    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $service = new ProductDeletionService;

    expect(fn () => $service->restore($product, $manager))->toThrow(Exception::class);
    expect($product->fresh()->trashed())->toBeTrue();

    $service->restore($product, $admin);
    expect(Product::find($product->id))->not->toBeNull();
    expect($product->fresh()->trashed())->toBeFalse();
});

it('refuses to restore a product that is not deleted', function () {
    test()->seed(ShieldSeeder::class);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $service = new ProductDeletionService;
    expect(fn () => $service->restore($product, $admin))->toThrow(Exception::class);
});
