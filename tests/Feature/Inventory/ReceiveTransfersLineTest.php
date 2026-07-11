<?php

use App\Filament\Pages\ReceiveTransfers;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\TransferDiscrepancy;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\StockTransferService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('lets a bartender receive a transfer line through the page, short of the sent amount', function () {
    Role::firstOrCreate(['name' => 'storekeeper']);
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    PagePermission::firstOrCreate(
        ['page_class' => ReceiveTransfers::class, 'role_name' => 'bartender'],
        ['page_class' => ReceiveTransfers::class, 'page_name' => 'Receive Transfers', 'role_name' => 'bartender']
    );

    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $main->id, 'quantity' => 50]);

    $storekeeper = User::factory()->create()->assignRole('storekeeper');
    $transfer = app(StockTransferService::class)->createTransfer($main->id, $bar->id, $storekeeper->id, [
        ['product_id' => $product->id, 'quantity' => 10],
    ]);
    $item = $transfer->items->first();

    Livewire::actingAs($bartender)
        ->test(ReceiveTransfers::class)
        ->call('load')
        ->call('receiveLine', $item->id, 'product', '6');

    expect($item->fresh()->outcome)->toBe('received_short');
    expect((float) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $bar->id)->value('quantity'))->toBe(6.0);
    expect(TransferDiscrepancy::count())->toBe(1);
});

it('denies page access to a role with no receiving PagePermission grant, before receiveLine is ever reachable', function () {
    Role::firstOrCreate(['name' => 'waiter']);
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    Livewire::actingAs($waiter)
        ->test(ReceiveTransfers::class)
        ->assertForbidden();
});
