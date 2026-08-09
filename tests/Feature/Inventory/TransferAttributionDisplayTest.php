<?php

use App\Filament\Pages\ReceiveTransfers;
use App\Filament\Pages\StorekeeperTransfers;
use App\Models\Category;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\WareHouse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Neither "Recent Transfers" (Storekeeper Transfers page) nor the
 * incoming/past lists on Receive Transfers ever showed who created a
 * transfer or who actually received a line — both were stored (user_id
 * on StockTransfer, received_by per line) but never surfaced, which came
 * up when the user asked "will the name of the person that did the
 * stock transfer show?"
 */
function seedAttributionFixtures(): array
{
    $storekeeper = User::factory()->create(['name' => 'Molly Storekeeper']);
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));
    $receiver = User::factory()->create(['name' => 'Bruno Bartender']);
    $receiver->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    PagePermission::firstOrCreate(
        ['page_class' => StorekeeperTransfers::class, 'role_name' => 'storekeeper'],
        ['page_class' => StorekeeperTransfers::class, 'page_name' => 'Storekeeper Transfers', 'role_name' => 'storekeeper']
    );
    PagePermission::firstOrCreate(
        ['page_class' => ReceiveTransfers::class, 'role_name' => 'bartender'],
        ['page_class' => ReceiveTransfers::class, 'page_name' => 'Receive Transfers', 'role_name' => 'bartender']
    );

    $mainStore = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Star Beer', 'category_id' => $category->id, 'price' => 500, 'is_active' => true]);

    $transfer = StockTransfer::create([
        'transfer_number' => 'ST-ATTR-1', 'from_warehouse_id' => $mainStore->id, 'to_warehouse_id' => $bar->id,
        'user_id' => $storekeeper->id, 'status' => 'received',
    ]);

    StockTransferItem::create([
        'stock_transfer_id' => $transfer->id, 'product_id' => $beer->id,
        'quantity' => 10, 'received_quantity' => 10, 'outcome' => 'received_full',
        'received_by' => $receiver->id,
    ]);

    return compact('storekeeper', 'receiver', 'transfer');
}

it('shows who created the transfer on the Storekeeper Transfers Recent Transfers list', function () {
    ['storekeeper' => $storekeeper] = seedAttributionFixtures();

    $html = Livewire::actingAs($storekeeper)
        ->test(StorekeeperTransfers::class)
        ->call('load')
        ->html();

    expect($html)->toContain('Created by:');
    expect($html)->toContain('Molly Storekeeper');
});

it('shows who received each line on the Storekeeper Transfers Recent Transfers list', function () {
    ['storekeeper' => $storekeeper] = seedAttributionFixtures();

    $html = Livewire::actingAs($storekeeper)
        ->test(StorekeeperTransfers::class)
        ->call('load')
        ->html();

    expect($html)->toContain('Bruno Bartender');
});

it('shows who created and who received on the Receive Transfers past transfers list', function () {
    ['receiver' => $receiver, 'storekeeper' => $storekeeper] = seedAttributionFixtures();

    $html = Livewire::actingAs($receiver)
        ->test(ReceiveTransfers::class)
        ->call('load')
        ->html();

    expect($html)->toContain('Created by:');
    expect($html)->toContain('Molly Storekeeper');
    expect($html)->toContain('Bruno Bartender');
});
