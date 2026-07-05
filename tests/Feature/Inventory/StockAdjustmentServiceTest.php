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

it('applies stock and logs a transaction when approved by someone other than the requester', function () {
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

    $requester = User::factory()->create();
    $approver = User::factory()->create();

    $service = new StockAdjustmentService();
    $adjustment = $service->request([
        'item_type' => 'product',
        'item_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_change' => -5,
        'reason' => 'damage',
    ], $requester->id);

    $service->approve($adjustment, $approver->id);

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
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

    $admin = User::factory()->create();
    $admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $service = new StockAdjustmentService();
    $adjustment = $service->request([
        'item_type' => 'product',
        'item_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_change' => -5,
        'reason' => 'damage',
    ], $admin->id);

    expect(fn () => $service->approve($adjustment, $admin->id))->toThrow(Exception::class);

    expect((int) InventoryItem::where('product_id', $product->id)->value('quantity'))->toBe(20);
    expect($adjustment->fresh()->status)->toBe('pending');
});

it('rejects an adjustment without touching stock', function () {
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

    $requester = User::factory()->create();
    $reviewer = User::factory()->create();

    $service = new StockAdjustmentService();
    $adjustment = $service->request([
        'item_type' => 'product',
        'item_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_change' => -5,
        'reason' => 'damage',
    ], $requester->id);

    $service->reject($adjustment, $reviewer->id, 'Not enough evidence of damage');

    expect((int) InventoryItem::where('product_id', $product->id)->value('quantity'))->toBe(20);
    expect($adjustment->fresh()->status)->toBe('rejected');
    expect($adjustment->fresh()->rejection_reason)->toBe('Not enough evidence of damage');
});

it('refuses an approval that would push stock below zero', function () {
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 3]);

    $requester = User::factory()->create();
    $approver = User::factory()->create();

    $service = new StockAdjustmentService();
    $adjustment = $service->request([
        'item_type' => 'product',
        'item_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_change' => -10,
        'reason' => 'damage',
    ], $requester->id);

    expect(fn () => $service->approve($adjustment, $approver->id))->toThrow(Exception::class);
    expect((int) InventoryItem::where('product_id', $product->id)->value('quantity'))->toBe(3);
});

it('supports ingredient adjustments the same way as products', function () {
    $warehouse = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);
    $ingredient = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-RICE-ADJ', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 5, 'category' => 'Dry Goods']);
    IngredientInventoryItem::create(['ingredient_id' => $ingredient->id, 'warehouse_id' => $warehouse->id, 'quantity' => 10]);

    $requester = User::factory()->create();
    $approver = User::factory()->create();

    $service = new StockAdjustmentService();
    $adjustment = $service->request([
        'item_type' => 'ingredient',
        'item_id' => $ingredient->id,
        'warehouse_id' => $warehouse->id,
        'quantity_change' => 2.5,
        'reason' => 'count_correction',
    ], $requester->id);

    $service->approve($adjustment, $approver->id);

    expect((float) IngredientInventoryItem::where('ingredient_id', $ingredient->id)->value('quantity'))->toEqual(12.5);
    expect(IngredientTransaction::where('ingredient_id', $ingredient->id)->where('type', 'adjustment')->count())->toBe(1);
});

it('refuses to approve or reject an adjustment that is not pending', function () {
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 20]);

    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $another = User::factory()->create();

    $service = new StockAdjustmentService();
    $adjustment = $service->request([
        'item_type' => 'product',
        'item_id' => $product->id,
        'warehouse_id' => $warehouse->id,
        'quantity_change' => -5,
        'reason' => 'damage',
    ], $requester->id);

    $service->approve($adjustment, $approver->id);

    expect(fn () => $service->approve($adjustment->fresh(), $another->id))->toThrow(Exception::class);
    expect(fn () => $service->reject($adjustment->fresh(), $another->id, 'too late'))->toThrow(Exception::class);
});
