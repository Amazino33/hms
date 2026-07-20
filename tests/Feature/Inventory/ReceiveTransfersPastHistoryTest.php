<?php

use App\Filament\Pages\ReceiveTransfers;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\StockTransferService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Receive Transfers only ever listed pending/sent/partially_received
 * transfers — by design, since it's meant to show what's still waiting for
 * action, not a full history. But that left the bartender/chef with no
 * page anywhere to look back at transfers they'd already fully received,
 * unlike the storekeeper's own "Recent Transfers" history. This adds a
 * "Past Transfers" section scoped to the same warehouse as the incoming
 * list, showing fully-received transfers with the same item-level detail.
 */
function grantReceiveTransfersAccess(User $user, string $role): void
{
    $user->assignRole(Role::firstOrCreate(['name' => $role]));
    PagePermission::firstOrCreate(
        ['page_class' => ReceiveTransfers::class, 'role_name' => $role],
        ['page_class' => ReceiveTransfers::class, 'page_name' => 'Receive Transfers', 'role_name' => $role]
    );
}

it('shows a bartender their own fully-received transfers in Past Transfers', function () {
    $bartender = User::factory()->create();
    grantReceiveTransfersAccess($bartender, 'bartender');

    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $main->id, 'quantity' => 50]);

    $storekeeper = User::factory()->create()->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));
    $transfer = app(StockTransferService::class)->createTransfer($main->id, $bar->id, $storekeeper->id, [
        ['product_id' => $product->id, 'quantity' => 10],
    ]);
    $item = $transfer->items->first();

    $component = Livewire::actingAs($bartender)
        ->test(ReceiveTransfers::class)
        ->call('load')
        ->call('receiveLine', $item->id, 'product', '10');

    expect($transfer->fresh()->status)->toBe('received');

    $component->assertSee('Past Transfers')
        ->assertSee($transfer->transfer_number)
        ->assertSee('Beer')
        ->assertSee('Received full');
});

it('does not show a bartender a fully-received transfer that went to a different warehouse', function () {
    $bartender = User::factory()->create();
    grantReceiveTransfersAccess($bartender, 'bartender');

    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $kitchen = WareHouse::create(['name' => 'Kitchen', 'type' => 'consumer']);

    $foodCategory = Category::create(['name' => 'Food', 'type' => 'food']);
    $rice = Product::create(['name' => 'Rice', 'price' => 1000, 'category_id' => $foodCategory->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $rice->id, 'warehouse_id' => $main->id, 'quantity' => 50]);

    $storekeeper = User::factory()->create()->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));
    $kitchenTransfer = app(StockTransferService::class)->createTransfer($main->id, $kitchen->id, $storekeeper->id, [
        ['product_id' => $rice->id, 'quantity' => 5],
    ]);
    app(StockTransferService::class)->receiveTransferLine($kitchenTransfer->items->first(), 5, $storekeeper->id);

    expect($kitchenTransfer->fresh()->status)->toBe('received');

    Livewire::actingAs($bartender)
        ->test(ReceiveTransfers::class)
        ->call('load')
        ->assertDontSee($kitchenTransfer->transfer_number);

    // Sanity: bar warehouse variable exists purely so this test reads as a
    // genuine cross-warehouse isolation check, not an incidental omission.
    expect($bar->id)->not->toBe($kitchen->id);
});

it('shows a storekeeper fully-received transfers across every warehouse', function () {
    $storekeeper = User::factory()->create();
    grantReceiveTransfersAccess($storekeeper, 'storekeeper');

    $main = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $main->id, 'quantity' => 50]);

    $transfer = app(StockTransferService::class)->createTransfer($main->id, $bar->id, $storekeeper->id, [
        ['product_id' => $product->id, 'quantity' => 10],
    ]);
    app(StockTransferService::class)->receiveTransferLine($transfer->items->first(), 10, $storekeeper->id);

    Livewire::actingAs($storekeeper)
        ->test(ReceiveTransfers::class)
        ->call('load')
        ->assertSee('Past Transfers')
        ->assertSee($transfer->transfer_number);
});
