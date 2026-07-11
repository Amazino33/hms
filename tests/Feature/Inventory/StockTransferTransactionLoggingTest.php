<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\StockTransferService;
use Spatie\Permission\Models\Role;

it('logs a transfer transaction on both legs when a product transfer is received', function () {
    Role::firstOrCreate(['name' => 'storekeeper']);
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole('storekeeper');

    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $main->id, 'quantity' => 50]);

    $service = new StockTransferService();
    $transfer = $service->createTransfer($main->id, $bar->id, $storekeeper->id, [
        ['product_id' => $product->id, 'quantity' => 10],
    ]);

    $service->receiveTransfer($transfer, $storekeeper->id);

    expect((int) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $main->id)->value('quantity'))->toBe(40);
    expect((int) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $bar->id)->value('quantity'))->toBe(10);

    $out = InventoryTransaction::where('product_id', $product->id)->where('reference', "transfer:{$transfer->id}:out")->first();
    $in = InventoryTransaction::where('product_id', $product->id)->where('reference', "transfer:{$transfer->id}:in")->first();

    expect($out)->not->toBeNull();
    expect($out->warehouse_id)->toBe($main->id);
    expect((int) $out->quantity)->toBe(10);

    expect($in)->not->toBeNull();
    expect($in->warehouse_id)->toBe($bar->id);
    expect((int) $in->quantity)->toBe(10);
});

it('supports transferring ingredients through the same shared stock_transfers header', function () {
    Role::firstOrCreate(['name' => 'storekeeper']);
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole('storekeeper');

    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);

    $ingredient = Ingredient::create(['name' => 'Flour', 'sku' => 'ING-FLOUR', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 10, 'category' => 'Dry Goods']);
    IngredientInventoryItem::create(['ingredient_id' => $ingredient->id, 'warehouse_id' => $main->id, 'quantity' => 30]);

    $service = new StockTransferService();
    $transfer = $service->createTransfer($main->id, $kitchen->id, $storekeeper->id, [], [
        ['ingredient_id' => $ingredient->id, 'quantity' => 12],
    ]);

    $service->receiveTransfer($transfer, $storekeeper->id);

    expect((float) IngredientInventoryItem::where('ingredient_id', $ingredient->id)->where('warehouse_id', $main->id)->value('quantity'))->toEqual(18.0);
    expect((float) IngredientInventoryItem::where('ingredient_id', $ingredient->id)->where('warehouse_id', $kitchen->id)->value('quantity'))->toEqual(12.0);

    $txn = IngredientTransaction::where('ingredient_id', $ingredient->id)->where('type', 'transfer')->count();
    expect($txn)->toBe(2); // one out, one in
});

it('throws when creating a transfer with insufficient source ingredient stock', function () {
    Role::firstOrCreate(['name' => 'storekeeper']);
    $storekeeper = User::factory()->create();

    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);

    $ingredient = Ingredient::create(['name' => 'Sugar', 'sku' => 'ING-SUGAR', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 5, 'category' => 'Dry Goods']);
    IngredientInventoryItem::create(['ingredient_id' => $ingredient->id, 'warehouse_id' => $main->id, 'quantity' => 5]);

    $service = new StockTransferService();

    expect(fn () => $service->createTransfer($main->id, $kitchen->id, $storekeeper->id, [], [
        ['ingredient_id' => $ingredient->id, 'quantity' => 20],
    ]))->toThrow(Exception::class);
});
