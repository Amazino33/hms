<?php

use App\Models\Category;
use App\Models\CountSession;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\StaffDebtRepayment;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\TransferDiscrepancy;
use App\Models\User;
use App\Models\WareHouse;

function seedDebtsAndTransfersFixtures(): array
{
    $waiter = User::factory()->create();
    $manager = User::factory()->create();
    $storekeeper = User::factory()->create();

    $mainStore = WareHouse::firstOrCreate(['id' => 1], ['name' => 'Main Store', 'is_active' => 1]);
    $bar = WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'is_active' => 1]);

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Beer', 'price' => 500, 'cost_price' => 200, 'category_id' => $category->id, 'is_active' => true]);

    $inventoryItem = InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $bar->id, 'quantity' => 24]);

    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    $order = Order::create([
        'order_number' => 'ORD-DT-1', 'user_id' => $waiter->id, 'shift_id' => $shift->id,
        'status' => 'paid', 'total_amount' => 500, 'amount_paid' => 500,
    ]);

    $debt = StaffDebt::create([
        'user_id' => $waiter->id, 'shift_id' => $shift->id, 'order_id' => $order->id,
        'amount' => 50, 'reason' => 'unpaid_order_conversion', 'status' => 'open', 'created_by' => $waiter->id,
    ]);
    StaffDebtRepayment::create(['staff_debt_id' => $debt->id, 'amount' => 20, 'method' => 'cash', 'recorded_by' => $manager->id]);

    $transfer = StockTransfer::create([
        'transfer_number' => 'ST-DT-1', 'from_warehouse_id' => $mainStore->id, 'to_warehouse_id' => $bar->id,
        'user_id' => $storekeeper->id, 'status' => 'sent',
    ]);
    $transferItem = StockTransferItem::create(['stock_transfer_id' => $transfer->id, 'product_id' => $product->id, 'quantity' => 10]);
    $discrepancy = TransferDiscrepancy::create(['stock_transfer_item_id' => $transferItem->id, 'missing_base_qty' => 2, 'status' => 'open']);

    $ledgerTransaction = InventoryTransaction::create([
        'product_id' => $product->id, 'warehouse_id' => $bar->id, 'type' => 'transfer',
        'quantity' => 10, 'reference' => "transfer:{$transfer->id}:in", 'user_id' => $storekeeper->id,
    ]);

    $countSession = CountSession::create([
        'type' => 'bar_handover', 'warehouse_id' => $bar->id, 'status' => 'counting',
        'opened_by' => $waiter->id, 'opened_at' => now(),
    ]);

    return compact(
        'waiter', 'manager', 'storekeeper', 'product', 'inventoryItem', 'shift',
        'order', 'debt', 'transfer', 'transferItem', 'discrepancy', 'ledgerTransaction', 'countSession',
    );
}

it('clears staff debts and stock transfers, cascading to their child records', function () {
    seedDebtsAndTransfersFixtures();

    expect(StaffDebt::count())->toBeGreaterThan(0);
    expect(StaffDebtRepayment::count())->toBeGreaterThan(0);
    expect(StockTransfer::count())->toBeGreaterThan(0);
    expect(StockTransferItem::count())->toBeGreaterThan(0);
    expect(TransferDiscrepancy::count())->toBeGreaterThan(0);

    $this->artisan('app:clear-debts-and-transfers', ['--force' => true])
        ->assertExitCode(0);

    expect(StaffDebt::count())->toBe(0);
    expect(StaffDebtRepayment::count())->toBe(0);
    expect(StockTransfer::count())->toBe(0);
    expect(StockTransferItem::count())->toBe(0);
    expect(TransferDiscrepancy::count())->toBe(0);
});

it('never touches stock quantities, the transaction ledger, orders, shifts, or count sessions', function () {
    [
        'product' => $product, 'inventoryItem' => $inventoryItem, 'order' => $order,
        'shift' => $shift, 'countSession' => $countSession, 'ledgerTransaction' => $ledgerTransaction,
    ] = seedDebtsAndTransfersFixtures();

    $this->artisan('app:clear-debts-and-transfers', ['--force' => true])
        ->assertExitCode(0);

    // The actual shelf quantity is untouched — this command only clears
    // history/accountability records, never live stock.
    expect(InventoryItem::find($inventoryItem->id)?->quantity)->toBe(24);
    expect(InventoryTransaction::find($ledgerTransaction->id))->not->toBeNull();
    expect(Order::find($order->id))->not->toBeNull();
    expect(Shift::find($shift->id))->not->toBeNull();
    expect(CountSession::find($countSession->id))->not->toBeNull();
    expect(Product::find($product->id))->not->toBeNull();
});

it('does nothing without --force when the confirmation is declined', function () {
    seedDebtsAndTransfersFixtures();

    $this->artisan('app:clear-debts-and-transfers')
        ->expectsConfirmation('Are you sure you want to continue?', 'no')
        ->assertExitCode(0);

    expect(StaffDebt::count())->toBeGreaterThan(0);
    expect(StockTransfer::count())->toBeGreaterThan(0);
});
