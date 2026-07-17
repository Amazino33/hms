<?php

use App\Models\Category;
use App\Models\DamageReport;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\DamageReportService;

function damageWarehouse(): WareHouse
{
    return WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
}

it('reports a pending damage without touching stock', function () {
    $bar = damageWarehouse();
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Heineken', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 20]);
    $reporter = User::factory()->create();

    $report = (new DamageReportService())->report(
        ['product_id' => $product->id, 'quantity' => 3, 'note' => 'Dropped tray'],
        $bar->id,
        $reporter->id
    );

    expect($report->status)->toBe('pending');
    expect(InventoryItem::where('product_id', $product->id)->value('quantity'))->toEqual(20.00);
    expect(InventoryTransaction::where('product_id', $product->id)->count())->toBe(0);
});

it('rejects a report with both a product and an ingredient set', function () {
    $bar = damageWarehouse();
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Heineken', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $ingredient = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-DMG-' . uniqid(), 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 5, 'category' => 'Dry Goods']);

    expect(fn () => (new DamageReportService())->report(
        ['product_id' => $product->id, 'ingredient_id' => $ingredient->id, 'quantity' => 1, 'note' => 'x'],
        $bar->id,
        User::factory()->create()->id
    ))->toThrow(Exception::class);
});

it('rejects a report with neither a product nor an ingredient set', function () {
    $bar = damageWarehouse();

    expect(fn () => (new DamageReportService())->report(
        ['quantity' => 1, 'note' => 'x'],
        $bar->id,
        User::factory()->create()->id
    ))->toThrow(Exception::class);
});

it('approving a product damage report decrements stock and writes a cost-valued damage_write_off transaction', function () {
    $bar = damageWarehouse();
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Heineken', 'price' => 500, 'cost_price' => 300, 'last_cost_price' => 320, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 20]);
    $manager = User::factory()->create();

    $service = new DamageReportService();
    $report = $service->report(['product_id' => $product->id, 'quantity' => 3, 'note' => 'Dropped tray'], $bar->id, User::factory()->create()->id);

    $approved = $service->approve($report, $manager->id, 'Confirmed with CCTV');

    expect($approved->status)->toBe('approved');
    expect($approved->resolved_by)->toBe($manager->id);
    expect(InventoryItem::where('product_id', $product->id)->value('quantity'))->toEqual(17.00);

    $transaction = InventoryTransaction::where('product_id', $product->id)->where('type', 'damage_write_off')->firstOrFail();
    expect((float) $transaction->quantity)->toBe(3.0);
    // Valued at cost (last_cost_price), deliberately NOT the 500 selling price.
    expect((float) $transaction->cost_per_unit)->toBe(320.0);
    expect($approved->inventory_transaction_id)->toBe($transaction->id);
});

it('approving an ingredient damage report decrements ingredient stock and writes a cost-valued transaction', function () {
    $store = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $rice = Ingredient::create(['name' => 'Rice', 'sku' => 'ING-DMG2-' . uniqid(), 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 5.5, 'category' => 'Dry Goods']);
    IngredientInventoryItem::create(['ingredient_id' => $rice->id, 'warehouse_id' => $store->id, 'quantity' => 50]);
    $manager = User::factory()->create();

    $service = new DamageReportService();
    $report = $service->report(['ingredient_id' => $rice->id, 'quantity' => 10, 'note' => 'Spoiled'], $store->id, User::factory()->create()->id);
    $service->approve($report, $manager->id);

    expect(IngredientInventoryItem::where('ingredient_id', $rice->id)->value('quantity'))->toEqual(40.00);

    $transaction = IngredientTransaction::where('ingredient_id', $rice->id)->where('type', 'damage_write_off')->firstOrFail();
    expect((float) $transaction->cost_per_unit)->toBe(5.5);
});

it('refuses to approve a write-off larger than what is currently on hand', function () {
    $bar = damageWarehouse();
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Heineken', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 2]);

    $service = new DamageReportService();
    $report = $service->report(['product_id' => $product->id, 'quantity' => 5, 'note' => 'x'], $bar->id, User::factory()->create()->id);

    expect(fn () => $service->approve($report, User::factory()->create()->id))->toThrow(Exception::class);
    expect(InventoryItem::where('product_id', $product->id)->value('quantity'))->toEqual(2.00);
});

it('rejecting a report requires a resolution note and changes no stock', function () {
    $bar = damageWarehouse();
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Heineken', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 20]);
    $manager = User::factory()->create();

    $service = new DamageReportService();
    $report = $service->report(['product_id' => $product->id, 'quantity' => 3, 'note' => 'Dropped tray'], $bar->id, User::factory()->create()->id);

    expect(fn () => $service->reject($report, $manager->id, ''))->toThrow(Exception::class);

    $rejected = $service->reject($report->fresh(), $manager->id, 'No evidence of breakage');

    expect($rejected->status)->toBe('rejected');
    expect(InventoryItem::where('product_id', $product->id)->value('quantity'))->toEqual(20.00);
    expect(InventoryTransaction::where('product_id', $product->id)->count())->toBe(0);
});

it('refuses to resolve an already-resolved report a second time, in either direction', function () {
    $bar = damageWarehouse();
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Heineken', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 20]);
    $manager = User::factory()->create();

    $service = new DamageReportService();
    $report = $service->report(['product_id' => $product->id, 'quantity' => 3, 'note' => 'x'], $bar->id, User::factory()->create()->id);
    $service->approve($report, $manager->id);

    expect(fn () => $service->approve($report->fresh(), $manager->id))->toThrow(Exception::class);
    expect(fn () => $service->reject($report->fresh(), $manager->id, 'too late'))->toThrow(Exception::class);

    // Only one write-off transaction exists — the second approve attempt
    // did not double the stock deduction.
    expect(InventoryTransaction::where('product_id', $product->id)->where('type', 'damage_write_off')->count())->toBe(1);
});
