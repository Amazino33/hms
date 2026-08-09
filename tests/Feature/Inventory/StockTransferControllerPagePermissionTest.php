<?php

use App\Filament\Pages\ReceiveTransfers;
use App\Filament\Pages\StorekeeperTransfers;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\StockTransferService;
use Spatie\Permission\Models\Role;

/**
 * Production incident: a cashier was asked to temporarily cover a
 * suspended storekeeper's transfer duties. A manager granted "Storekeeper
 * Transfers" page access via Page Permissions Manager, but submitting the
 * actual form still failed with "Forbidden" — this controller had its own
 * hardcoded ['storekeeper', 'super_admin'] / ['storekeeper', 'chef',
 * 'bartender'] role arrays, completely disconnected from PagePermission.
 * These tests lock in that store/send/receive/bulkReceive now all consult
 * the same PagePermission grant the Page itself uses to decide who can
 * even see it.
 */
function grantPageAccess(string $pageClass, string $roleName): void
{
    PagePermission::firstOrCreate(
        ['page_class' => $pageClass, 'role_name' => $roleName],
        ['page_class' => $pageClass, 'page_name' => 'Test Page', 'role_name' => $roleName]
    );
}

function makeTransferForController(): array
{
    $mainStore = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Star Beer', 'category_id' => $category->id, 'price' => 500, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $mainStore->id, 'quantity' => 100]);

    return compact('mainStore', 'bar', 'product');
}

it('lets a cashier granted the Storekeeper Transfers page permission create a transfer', function () {
    ['mainStore' => $mainStore, 'bar' => $bar, 'product' => $product] = makeTransferForController();

    grantPageAccess(StorekeeperTransfers::class, 'cashier');
    $cashier = User::factory()->create();
    $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier']));

    $response = $this->actingAs($cashier)->postJson('/stock-transfers', [
        'from_warehouse_id' => $mainStore->id,
        'to_warehouse_id' => $bar->id,
        'items' => [['product_id' => $product->id, 'quantity' => 5]],
    ]);

    $response->assertOk();
    expect(StockTransfer::count())->toBe(1);
});

it('refuses to create a transfer for a role with no Storekeeper Transfers page grant', function () {
    ['mainStore' => $mainStore, 'bar' => $bar, 'product' => $product] = makeTransferForController();

    $waiter = User::factory()->create();
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    $response = $this->actingAs($waiter)->postJson('/stock-transfers', [
        'from_warehouse_id' => $mainStore->id,
        'to_warehouse_id' => $bar->id,
        'items' => [['product_id' => $product->id, 'quantity' => 5]],
    ]);

    $response->assertForbidden();
    expect(StockTransfer::count())->toBe(0);
});

it('lets super_admin create and send a transfer with no explicit page grant needed', function () {
    ['mainStore' => $mainStore, 'bar' => $bar, 'product' => $product] = makeTransferForController();

    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $create = $this->actingAs($admin)->postJson('/stock-transfers', [
        'from_warehouse_id' => $mainStore->id,
        'to_warehouse_id' => $bar->id,
        'items' => [['product_id' => $product->id, 'quantity' => 5]],
    ]);
    $create->assertOk();

    $transfer = StockTransfer::firstOrFail();

    $send = $this->actingAs($admin)->postJson("/stock-transfers/{$transfer->id}/send");
    $send->assertOk();
    expect($transfer->fresh()->status)->toBe('sent');
});

it('lets a role granted the Receive Transfers page permission receive a transfer', function () {
    ['mainStore' => $mainStore, 'bar' => $bar, 'product' => $product] = makeTransferForController();

    grantPageAccess(ReceiveTransfers::class, 'cashier');
    $cashier = User::factory()->create();
    $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier']));

    $storekeeper = User::factory()->create();
    $transfer = (new StockTransferService)->createTransfer($mainStore->id, $bar->id, $storekeeper->id, [
        ['product_id' => $product->id, 'quantity' => 5],
    ]);

    $response = $this->actingAs($cashier)->postJson("/stock-transfers/{$transfer->id}/receive");

    $response->assertOk();
    expect($transfer->fresh()->status)->toBe('received');
});

it('refuses to receive a transfer for a role with no Receive Transfers page grant', function () {
    ['mainStore' => $mainStore, 'bar' => $bar, 'product' => $product] = makeTransferForController();

    $storekeeper = User::factory()->create();
    $transfer = (new StockTransferService)->createTransfer($mainStore->id, $bar->id, $storekeeper->id, [
        ['product_id' => $product->id, 'quantity' => 5],
    ]);

    $waiter = User::factory()->create();
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    $response = $this->actingAs($waiter)->postJson("/stock-transfers/{$transfer->id}/receive");

    $response->assertForbidden();
    expect($transfer->fresh()->status)->toBe('pending');
});

it('lets a role granted the Receive Transfers page permission bulk-receive transfers', function () {
    ['mainStore' => $mainStore, 'bar' => $bar, 'product' => $product] = makeTransferForController();

    grantPageAccess(ReceiveTransfers::class, 'cashier');
    $cashier = User::factory()->create();
    $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier']));

    $storekeeper = User::factory()->create();
    $transfer = (new StockTransferService)->createTransfer($mainStore->id, $bar->id, $storekeeper->id, [
        ['product_id' => $product->id, 'quantity' => 5],
    ]);

    $response = $this->actingAs($cashier)->postJson('/stock-transfers/bulk-receive', [
        'transfer_ids' => [$transfer->id],
    ]);

    $response->assertOk();
    expect($transfer->fresh()->status)->toBe('received');
});

it('refuses to bulk-receive for a role with no Receive Transfers page grant', function () {
    ['mainStore' => $mainStore, 'bar' => $bar, 'product' => $product] = makeTransferForController();

    $storekeeper = User::factory()->create();
    $transfer = (new StockTransferService)->createTransfer($mainStore->id, $bar->id, $storekeeper->id, [
        ['product_id' => $product->id, 'quantity' => 5],
    ]);

    $waiter = User::factory()->create();
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    $response = $this->actingAs($waiter)->postJson('/stock-transfers/bulk-receive', [
        'transfer_ids' => [$transfer->id],
    ]);

    $response->assertForbidden();
    expect($transfer->fresh()->status)->toBe('pending');
});
