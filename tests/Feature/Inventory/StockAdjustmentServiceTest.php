<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\StockAdjustmentService;
use Database\Seeders\ShieldSeeder;

it('does not touch stock when a request is created — only pending', function () {
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

    $requester = User::factory()->create();

    $service = new StockAdjustmentService();
    $adjustment = $service->request([
        'item_type' => 'product',
        'item_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_change' => -5,
        'reason' => 'damage',
        'notes' => 'Crate dropped',
    ], $requester->id);

    expect($adjustment->status)->toBe('pending');
    expect((int) InventoryItem::where('product_id', $product->id)->value('quantity'))->toBe(20);
    expect(InventoryTransaction::count())->toBe(0);
});

it('applies stock and logs a transaction when approved by a manager other than the requester', function () {
    test()->seed(ShieldSeeder::class);
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $approver->assignRole('manager');

    $service = new StockAdjustmentService();
    $adjustment = $service->request([
        'item_type' => 'product',
        'item_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_change' => -5,
        'reason' => 'damage',
    ], $requester->id);

    $service->approve($adjustment, $approver);

    expect((int) InventoryItem::where('product_id', $product->id)->value('quantity'))->toBe(15);

    $txn = InventoryTransaction::where('product_id', $product->id)->where('type', 'adjustment')->first();
    expect($txn)->not->toBeNull();
    expect((float) $txn->quantity)->toEqual(5.0);
    expect($txn->user_id)->toBe($approver->id);
    expect($txn->reference)->toBe("stock_adjustment:{$adjustment->id}:damage");

    expect($adjustment->fresh()->status)->toBe('approved');
    expect($adjustment->fresh()->reviewed_by)->toBe($approver->id);
});

it('refuses to let the requester approve their own adjustment, even for super_admin', function () {
    test()->seed(ShieldSeeder::class);
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $service = new StockAdjustmentService();
    $adjustment = $service->request([
        'item_type' => 'product',
        'item_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_change' => -5,
        'reason' => 'damage',
    ], $admin->id);

    expect(fn () => $service->approve($adjustment, $admin))->toThrow(Exception::class);

    expect((int) InventoryItem::where('product_id', $product->id)->value('quantity'))->toBe(20);
    expect($adjustment->fresh()->status)->toBe('pending');
});

it('refuses to let a peer without Update:StockAdjustment approve, even though they are not the requester', function () {
    test()->seed(ShieldSeeder::class);
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

    $requester = User::factory()->create();
    $requester->assignRole('bartender');
    $peer = User::factory()->create();
    $peer->assignRole('storekeeper'); // has Create:StockAdjustment but not Update:StockAdjustment

    $service = new StockAdjustmentService();
    $adjustment = $service->request([
        'item_type' => 'product',
        'item_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_change' => -5,
        'reason' => 'damage',
    ], $requester->id);

    expect(fn () => $service->approve($adjustment, $peer))->toThrow(Exception::class);
    expect(fn () => $service->reject($adjustment->fresh(), $peer, 'no'))->toThrow(Exception::class);

    expect((int) InventoryItem::where('product_id', $product->id)->value('quantity'))->toBe(20);
    expect($adjustment->fresh()->status)->toBe('pending');
});

it('rejects an adjustment without touching stock', function () {
    test()->seed(ShieldSeeder::class);
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

    $requester = User::factory()->create();
    $reviewer = User::factory()->create();
    $reviewer->assignRole('manager');

    $service = new StockAdjustmentService();
    $adjustment = $service->request([
        'item_type' => 'product',
        'item_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_change' => -5,
        'reason' => 'damage',
    ], $requester->id);

    $service->reject($adjustment, $reviewer, 'Not enough evidence of damage');

    expect((int) InventoryItem::where('product_id', $product->id)->value('quantity'))->toBe(20);
    expect($adjustment->fresh()->status)->toBe('rejected');
    expect($adjustment->fresh()->rejection_reason)->toBe('Not enough evidence of damage');
});

it('refuses an approval that would push stock below zero', function () {
    test()->seed(ShieldSeeder::class);
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 3]);

    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $approver->assignRole('manager');

    $service = new StockAdjustmentService();
    $adjustment = $service->request([
        'item_type' => 'product',
        'item_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_change' => -10,
        'reason' => 'damage',
    ], $requester->id);

    expect(fn () => $service->approve($adjustment, $approver))->toThrow(Exception::class);
    expect((int) InventoryItem::where('product_id', $product->id)->value('quantity'))->toBe(3);
});

it('supports ingredient adjustments the same way as products', function () {
    test()->seed(ShieldSeeder::class);
    $warehouse = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $ingredient = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-RICE-ADJ', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 5, 'category' => 'Dry Goods']);
    IngredientInventoryItem::create(['ingredient_id' => $ingredient->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10]);

    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $approver->assignRole('manager');

    $service = new StockAdjustmentService();
    $adjustment = $service->request([
        'item_type' => 'ingredient',
        'item_id' => $ingredient->id,
        'warehouse_id' => $warehouse->id,
        'quantity_change' => 2.5,
        'reason' => 'count_correction',
    ], $requester->id);

    $service->approve($adjustment, $approver);

    expect((float) IngredientInventoryItem::where('ingredient_id', $ingredient->id)->value('quantity'))->toEqual(12.5);
    expect(IngredientTransaction::where('ingredient_id', $ingredient->id)->where('type', 'adjustment')->count())->toBe(1);
});

it('refuses to approve or reject an adjustment that is not pending', function () {
    test()->seed(ShieldSeeder::class);
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $approver->assignRole('manager');
    $another = User::factory()->create();
    $another->assignRole('manager');

    $service = new StockAdjustmentService();
    $adjustment = $service->request([
        'item_type' => 'product',
        'item_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_change' => -5,
        'reason' => 'damage',
    ], $requester->id);

    $service->approve($adjustment, $approver);

    expect(fn () => $service->approve($adjustment->fresh(), $another))->toThrow(Exception::class);
    expect(fn () => $service->reject($adjustment->fresh(), $another, 'too late'))->toThrow(Exception::class);
});
