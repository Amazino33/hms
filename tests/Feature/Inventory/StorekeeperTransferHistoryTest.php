<?php

use App\Filament\Pages\StorekeeperTransfers;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\IngredientTransferItem;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\TransferDiscrepancy;
use App\Models\User;
use App\Models\WareHouse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Before this, "Recent Transfers" only showed a line COUNT ("Lines: 3"),
 * not what was actually transferred — no item names/quantities, no
 * sent-vs-received comparison, no discrepancy visibility. A storekeeper
 * reviewing history had to go elsewhere to see what actually happened to
 * a transfer.
 */
function seedStorekeeperTransferHistoryFixtures(): array
{
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));
    PagePermission::firstOrCreate(
        ['page_class' => StorekeeperTransfers::class, 'role_name' => 'storekeeper'],
        ['page_class' => StorekeeperTransfers::class, 'page_name' => 'Storekeeper Transfers', 'role_name' => 'storekeeper']
    );

    $mainStore = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Star Beer', 'category_id' => $category->id, 'price' => 500, 'is_active' => true]);
    $ingredient = Ingredient::create(['name' => 'Tomato', 'sku' => 'ING-TOMATO', 'unit_name' => 'kg', 'quantity' => 0, 'cost_per_unit' => 2, 'category' => 'Vegetables']);

    $transfer = StockTransfer::create([
        'transfer_number' => 'ST-HIST-1', 'from_warehouse_id' => $mainStore->id, 'to_warehouse_id' => $bar->id,
        'user_id' => $storekeeper->id, 'status' => 'partially_received',
    ]);

    $shortItem = StockTransferItem::create([
        'stock_transfer_id' => $transfer->id, 'product_id' => $beer->id,
        'quantity' => 24, 'received_quantity' => 20, 'outcome' => 'received_short',
    ]);

    TransferDiscrepancy::create([
        'stock_transfer_item_id' => $shortItem->id, 'missing_base_qty' => 4, 'status' => 'open',
    ]);

    IngredientTransferItem::create([
        'stock_transfer_id' => $transfer->id, 'ingredient_id' => $ingredient->id,
        'quantity' => 5, 'received_quantity' => 5, 'outcome' => 'received_full',
    ]);

    return compact('storekeeper', 'transfer', 'beer', 'ingredient');
}

it('shows item names, sent/received quantities, and outcome for each transfer line', function () {
    ['storekeeper' => $storekeeper] = seedStorekeeperTransferHistoryFixtures();

    $html = Livewire::actingAs($storekeeper)
        ->test(StorekeeperTransfers::class)
        ->call('load')
        ->html();

    expect($html)->toContain('Star Beer');
    expect($html)->toContain('sent 24');
    expect($html)->toContain('received 20');
    expect($html)->toContain('Received short');

    expect($html)->toContain('Tomato');
    expect($html)->toContain('sent 5');
    expect($html)->toContain('Received full');
});

it('flags an open discrepancy on the affected line', function () {
    ['storekeeper' => $storekeeper] = seedStorekeeperTransferHistoryFixtures();

    $html = Livewire::actingAs($storekeeper)
        ->test(StorekeeperTransfers::class)
        ->call('load')
        ->html();

    expect($html)->toContain('4 unresolved');
});

it('does not show a discrepancy flag once it has been resolved', function () {
    ['storekeeper' => $storekeeper, 'transfer' => $transfer] = seedStorekeeperTransferHistoryFixtures();

    TransferDiscrepancy::first()->update(['status' => 'written_off_missing']);

    $html = Livewire::actingAs($storekeeper)
        ->test(StorekeeperTransfers::class)
        ->call('load')
        ->html();

    expect($html)->not->toContain('unresolved');
});
