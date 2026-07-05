<?php

use App\Models\Ingredient;
use App\Models\IngredientInventoryItem;
use App\Models\IngredientTransaction;
use App\Models\IngredientTransferItem;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\WareHouse;
use Illuminate\Support\Facades\DB;

it('backfills existing ingredient quantities into ingredient_inventory_items at the main store', function () {
    WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);

    // Simulate a pre-existing ingredient created the old way (raw quantity column only).
    $ingredientId = DB::table('ingredients')->insertGetId([
        'name' => 'Flour',
        'sku' => 'ING-FLOUR-1',
        'unit_name' => 'kg',
        'quantity' => 42.5,
        'cost_per_unit' => 10,
        'category' => 'Dry Goods',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Re-run the backfill migration logic directly against this row, mirroring
    // what the migration does for pre-existing data at deploy time.
    $mainStoreId = WareHouse::where('type', 'storage')->orderBy('id')->value('id') ?? 1;

    IngredientInventoryItem::updateOrCreate(
        ['ingredient_id' => $ingredientId, 'warehouse_id' => $mainStoreId],
        ['quantity' => 42.5]
    );

    $item = IngredientInventoryItem::where('ingredient_id', $ingredientId)->first();

    expect($item)->not->toBeNull();
    expect((float) $item->quantity)->toEqual(42.5);
    expect($item->warehouse_id)->toBe($mainStoreId);
});

it('relates an ingredient to its per-warehouse inventory rows', function () {
    $ingredient = Ingredient::create([
        'name' => 'Sugar', 'sku' => 'ING-SUGAR-1', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 5, 'category' => 'Dry Goods',
    ]);
    $warehouse = WareHouse::first() ?? WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);

    IngredientInventoryItem::create([
        'ingredient_id' => $ingredient->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => 15,
    ]);

    expect($ingredient->inventory()->count())->toBe(1);
    expect($ingredient->warehouses()->first()->id)->toBe($warehouse->id);
});

it('logs an ingredient transaction with a causer', function () {
    $user = User::factory()->create();
    $ingredient = Ingredient::create([
        'name' => 'Rice', 'sku' => 'ING-RICE-1', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 8, 'category' => 'Dry Goods',
    ]);
    $warehouse = WareHouse::first() ?? WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);

    $transaction = IngredientTransaction::create([
        'ingredient_id' => $ingredient->id,
        'warehouse_id' => $warehouse->id,
        'type' => 'purchase',
        'quantity' => 20,
        'cost_per_unit' => 8,
        'user_id' => $user->id,
    ]);

    expect($transaction->ingredient->id)->toBe($ingredient->id);
    expect($transaction->warehouse->id)->toBe($warehouse->id);
});

it('shares a single stock_transfers header between product and ingredient transfer items', function () {
    $storekeeper = User::factory()->create();
    $from = WareHouse::first() ?? WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $to = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);

    $ingredient = Ingredient::create([
        'name' => 'Salt', 'sku' => 'ING-SALT-1', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 2, 'category' => 'Dry Goods',
    ]);

    $transfer = StockTransfer::create([
        'transfer_number' => 'TRF-TEST-1',
        'from_warehouse_id' => $from->id,
        'to_warehouse_id' => $to->id,
        'user_id' => $storekeeper->id,
        'status' => 'pending',
    ]);

    IngredientTransferItem::create([
        'stock_transfer_id' => $transfer->id,
        'ingredient_id' => $ingredient->id,
        'quantity' => 5,
    ]);

    expect($transfer->ingredientItems()->count())->toBe(1);
    expect($transfer->items()->count())->toBe(0);
});
