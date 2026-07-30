<?php

use App\Filament\Pages\StorekeeperTransfers;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\PagePermission;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\StockTransferService;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function grantStorekeeperTransfersPagePermission(): void
{
    PagePermission::firstOrCreate(
        ['page_class' => StorekeeperTransfers::class, 'role_name' => 'storekeeper'],
        ['page_class' => StorekeeperTransfers::class, 'page_name' => 'Storekeeper Transfers', 'role_name' => 'storekeeper']
    );
}

function makeCancellableTransfer(string $status = 'pending'): array
{
    $mainStore = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Star Beer', 'category_id' => $category->id, 'price' => 500, 'is_active' => true]);
    InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $mainStore->id, 'quantity' => 100]);

    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'storekeeper']));

    $transfer = (new StockTransferService())->createTransfer($mainStore->id, $bar->id, $storekeeper->id, [
        ['product_id' => $product->id, 'quantity' => 10],
    ]);

    if ($status !== 'pending') {
        $transfer->update(['status' => $status]);
    }

    return compact('transfer', 'storekeeper', 'product', 'mainStore', 'bar');
}

it('cancels a pending transfer, stamping who and why, with no stock movement', function () {
    ['transfer' => $transfer, 'storekeeper' => $storekeeper, 'product' => $product, 'mainStore' => $mainStore] = makeCancellableTransfer();

    $before = (float) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $mainStore->id)->value('quantity');

    $cancelled = (new StockTransferService())->cancelTransfer($transfer, 'Duplicate — created by mistake', $storekeeper->id);

    expect($cancelled->status)->toBe('cancelled');
    expect($cancelled->cancelled_reason)->toBe('Duplicate — created by mistake');
    expect($cancelled->cancelled_by)->toBe($storekeeper->id);
    expect($cancelled->cancelled_at)->not->toBeNull();

    $after = (float) InventoryItem::where('product_id', $product->id)->where('warehouse_id', $mainStore->id)->value('quantity');
    expect($after)->toBe($before);
});

it('refuses to cancel without a reason', function () {
    ['transfer' => $transfer, 'storekeeper' => $storekeeper] = makeCancellableTransfer();

    expect(fn () => (new StockTransferService())->cancelTransfer($transfer, '', $storekeeper->id))
        ->toThrow(Exception::class, 'A reason is required to cancel a transfer.');

    expect($transfer->fresh()->status)->toBe('pending');
});

it('refuses to cancel a transfer that has already been partially received', function () {
    ['transfer' => $transfer, 'storekeeper' => $storekeeper] = makeCancellableTransfer('partially_received');

    expect(fn () => (new StockTransferService())->cancelTransfer($transfer, 'Too late', $storekeeper->id))
        ->toThrow(Exception::class, 'Only a transfer that has not been received yet (even partially) can be cancelled.');
});

it('refuses to cancel an already fully received transfer', function () {
    ['transfer' => $transfer, 'storekeeper' => $storekeeper] = makeCancellableTransfer('received');

    expect(fn () => (new StockTransferService())->cancelTransfer($transfer, 'Too late', $storekeeper->id))
        ->toThrow(Exception::class);
});

it('lets a storekeeper cancel their own duplicate transfer through the real page component', function () {
    grantStorekeeperTransfersPagePermission();
    ['transfer' => $transfer, 'storekeeper' => $storekeeper] = makeCancellableTransfer();

    Livewire::actingAs($storekeeper)
        ->test(StorekeeperTransfers::class)
        ->call('load')
        ->call('startCancelTransfer', $transfer->id)
        ->set('cancelReason', 'Accidentally submitted twice')
        ->call('confirmCancelTransfer');

    $fresh = $transfer->fresh();
    expect($fresh->status)->toBe('cancelled');
    expect($fresh->cancelled_reason)->toBe('Accidentally submitted twice');
    expect($fresh->cancelled_by)->toBe($storekeeper->id);
});

it('does not cancel anything when confirmCancelTransfer is called with an empty reason', function () {
    grantStorekeeperTransfersPagePermission();
    ['transfer' => $transfer, 'storekeeper' => $storekeeper] = makeCancellableTransfer();

    Livewire::actingAs($storekeeper)
        ->test(StorekeeperTransfers::class)
        ->call('load')
        ->call('startCancelTransfer', $transfer->id)
        ->call('confirmCancelTransfer');

    expect($transfer->fresh()->status)->toBe('pending');
});
