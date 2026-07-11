<?php

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\TransferDiscrepancy;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\StockTransferService;
use Spatie\Permission\Models\Role;

function setUpTransferFixture(int $sentQty = 10): array
{
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
        ['product_id' => $product->id, 'quantity' => $sentQty],
    ]);

    return compact('service', 'transfer', 'main', 'bar', 'product', 'storekeeper');
}

it('receives a line in full, closing the transfer and crediting the destination exactly the sent amount', function () {
    ['service' => $service, 'transfer' => $transfer, 'bar' => $bar, 'product' => $product, 'storekeeper' => $storekeeper] = setUpTransferFixture(10);

    $item = $transfer->items->first();
    $service->receiveTransferLine($item, 10, $storekeeper->id);

    expect((float) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $bar->id)->value('quantity'))->toBe(10.0);
    expect($item->fresh()->outcome)->toBe('received_full');
    expect($transfer->fresh()->status)->toBe('received');
    expect(TransferDiscrepancy::count())->toBe(0);
});

it('opens a discrepancy on a short receipt without crediting the missing quantity anywhere', function () {
    ['service' => $service, 'transfer' => $transfer, 'main' => $main, 'bar' => $bar, 'product' => $product, 'storekeeper' => $storekeeper] = setUpTransferFixture(10);

    $item = $transfer->items->first();
    $service->receiveTransferLine($item, 7, $storekeeper->id);

    expect((float) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $bar->id)->value('quantity'))->toBe(7.0);
    // The full sent amount (10) leaves the source at receipt time; only 7 lands at the
    // destination — the missing 3 sits in neither ledger until a manager resolves it.
    expect((float) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $main->id)->value('quantity'))->toBe(40.0);

    expect($item->fresh()->outcome)->toBe('received_short');
    expect($transfer->fresh()->status)->toBe('received'); // only line, now resolved

    $discrepancy = TransferDiscrepancy::first();
    expect($discrepancy)->not->toBeNull();
    expect((float) $discrepancy->missing_base_qty)->toBe(3.0);
    expect($discrepancy->isOpen())->toBeTrue();
});

it('treats a zero receipt as rejected and opens a discrepancy for the full sent quantity', function () {
    ['service' => $service, 'transfer' => $transfer, 'bar' => $bar, 'product' => $product, 'storekeeper' => $storekeeper] = setUpTransferFixture(10);

    $item = $transfer->items->first();
    $service->receiveTransferLine($item, 0, $storekeeper->id);

    expect((float) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $bar->id)->value('quantity') ?? 0)->toBe(0.0);
    expect($item->fresh()->outcome)->toBe('rejected');
    expect((float) TransferDiscrepancy::first()->missing_base_qty)->toBe(10.0);
});

it('refuses to receive more than was sent', function () {
    ['service' => $service, 'transfer' => $transfer, 'storekeeper' => $storekeeper] = setUpTransferFixture(10);

    $item = $transfer->items->first();
    expect(fn () => $service->receiveTransferLine($item, 11, $storekeeper->id))->toThrow(Exception::class);
});

it('refuses to receive the same line twice', function () {
    ['service' => $service, 'transfer' => $transfer, 'storekeeper' => $storekeeper] = setUpTransferFixture(10);

    $item = $transfer->items->first();
    $service->receiveTransferLine($item, 10, $storekeeper->id);

    expect(fn () => $service->receiveTransferLine($item->fresh(), 10, $storekeeper->id))->toThrow(Exception::class);
});

it('keeps a multi-line transfer partially_received until every line is resolved, then auto-closes', function () {
    Role::firstOrCreate(['name' => 'storekeeper']);
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole('storekeeper');

    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);

    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $wine = Product::create(['name' => 'Wine', 'price' => 1000, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => $main->id, 'quantity' => 50]);
    InventoryItem::create(['product_id' => $wine->id, 'warehouse_id' => $main->id, 'quantity' => 50]);

    $service = new StockTransferService();
    $transfer = $service->createTransfer($main->id, $bar->id, $storekeeper->id, [
        ['product_id' => $beer->id, 'quantity' => 10],
        ['product_id' => $wine->id, 'quantity' => 5],
    ]);

    $beerItem = $transfer->items()->where('product_id', $beer->id)->first();
    $wineItem = $transfer->items()->where('product_id', $wine->id)->first();

    $service->receiveTransferLine($beerItem, 10, $storekeeper->id);
    expect($transfer->fresh()->status)->toBe('partially_received');

    $service->receiveTransferLine($wineItem, 5, $storekeeper->id);
    expect($transfer->fresh()->status)->toBe('received');
});

it('supports transferring and receiving ingredient lines the same way as products', function () {
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

    $item = $transfer->ingredientItems->first();
    $service->receiveTransferLine($item, 12, $storekeeper->id);

    expect((float) IngredientInventoryItem::where('ingredient_id', $ingredient->id)->where('warehouse_id', $kitchen->id)->value('quantity'))->toBe(12.0);
    expect($transfer->fresh()->status)->toBe('received');
});

it('reverses an open discrepancy back to the main store and marks it resolved', function () {
    ['service' => $service, 'transfer' => $transfer, 'main' => $main, 'product' => $product, 'storekeeper' => $storekeeper] = setUpTransferFixture(10);

    $item = $transfer->items->first();
    $service->receiveTransferLine($item, 7, $storekeeper->id);
    $discrepancy = TransferDiscrepancy::first();

    $manager = User::factory()->create();
    $service->reverseDiscrepancyToStore($discrepancy, $manager->id, 'Recount found the missing 3 still in the store.');

    expect((float) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $main->id)->value('quantity'))->toBe(43.0); // 40 + 3 reversed
    expect($discrepancy->fresh()->status)->toBe('reversed_to_store');
    expect($discrepancy->fresh()->resolved_by)->toBe($manager->id);
    expect($discrepancy->fresh()->reversal_inventory_transaction_id)->not->toBeNull();
});

it('writes off a discrepancy as missing without moving any stock', function () {
    ['service' => $service, 'transfer' => $transfer, 'main' => $main, 'product' => $product, 'storekeeper' => $storekeeper] = setUpTransferFixture(10);

    $item = $transfer->items->first();
    $service->receiveTransferLine($item, 7, $storekeeper->id);
    $discrepancy = TransferDiscrepancy::first();

    $manager = User::factory()->create();
    $service->writeOffDiscrepancy($discrepancy, $manager->id, 'Confirmed missing, filed incident report.');

    expect((float) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $main->id)->value('quantity'))->toBe(40.0); // unchanged
    expect($discrepancy->fresh()->status)->toBe('written_off_missing');
});

it('refuses to resolve a discrepancy twice', function () {
    ['service' => $service, 'transfer' => $transfer, 'storekeeper' => $storekeeper] = setUpTransferFixture(10);

    $item = $transfer->items->first();
    $service->receiveTransferLine($item, 7, $storekeeper->id);
    $discrepancy = TransferDiscrepancy::first();
    $manager = User::factory()->create();

    $service->writeOffDiscrepancy($discrepancy, $manager->id, 'note');

    expect(fn () => $service->writeOffDiscrepancy($discrepancy->fresh(), $manager->id, 'again'))->toThrow(Exception::class);
});
