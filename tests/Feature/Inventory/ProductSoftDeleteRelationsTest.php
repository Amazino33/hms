<?php

use App\Models\Category;
use App\Models\CountSession;
use App\Models\CountSessionItem;
use App\Models\DamageReport;
use App\Models\FridgeRestockMark;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Procurement;
use App\Models\ProcurementItem;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\WareHouse;

/**
 * Product now supports soft deletes. Every model below references it via a
 * plain belongsTo(), which Eloquent silently scopes to non-trashed rows —
 * so once a product is deleted, every one of these relations quietly
 * returned null instead of the product's own (still fully intact) name,
 * turning every history/reporting page that reads $record->product->name
 * into a blank cell. withTrashed() was added to each relation so historical
 * display keeps working exactly as it did before the product was deleted;
 * this is unrelated to catalog/business-logic queries (Product::all(),
 * new sales, etc.), which still correctly exclude it.
 */
function createTrashedProductFixture(): Product
{
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $product = Product::create(['name' => 'Origin Beer', 'price' => 500, 'category_id' => $category->id, 'is_active' => true]);
    $product->delete();

    return $product;
}

it('InventoryItem still resolves its product after deletion', function () {
    $product = createTrashedProductFixture();
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $item = InventoryItem::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'quantity' => 12]);

    expect($item->product?->name)->toBe('Origin Beer');
});

it('InventoryTransaction still resolves its product after deletion', function () {
    $product = createTrashedProductFixture();
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $user = User::factory()->create();
    $txn = InventoryTransaction::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'type' => 'purchase', 'quantity' => 12, 'user_id' => $user->id]);

    expect($txn->product?->name)->toBe('Origin Beer');
});

it('OrderItem still resolves its product after deletion', function () {
    $product = createTrashedProductFixture();
    $order = Order::create(['order_number' => 'ORD-' . uniqid(), 'status' => 'paid', 'total_amount' => 500, 'amount_paid' => 500]);
    $item = OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Origin Beer', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 500, 'subtotal' => 500]);

    expect($item->product?->name)->toBe('Origin Beer');
});

it('StockTransferItem still resolves its product after deletion', function () {
    $product = createTrashedProductFixture();
    $from = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $to = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $storekeeper = User::factory()->create();
    $transfer = StockTransfer::create(['transfer_number' => 'ST-' . uniqid(), 'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id, 'user_id' => $storekeeper->id, 'status' => 'pending']);
    $item = StockTransferItem::create(['stock_transfer_id' => $transfer->id, 'product_id' => $product->id, 'quantity' => 10]);

    expect($item->product?->name)->toBe('Origin Beer');
});

it('StockAdjustment still resolves its product after deletion', function () {
    $product = createTrashedProductFixture();
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $requester = User::factory()->create();
    $adjustment = StockAdjustment::create([
        'item_type' => 'product', 'product_id' => $product->id, 'warehouse_id' => $warehouse->id,
        'quantity_change' => -2, 'reason' => 'theft_suspected', 'status' => 'pending', 'requested_by' => $requester->id,
    ]);

    expect($adjustment->product?->name)->toBe('Origin Beer');
});

it('CountSessionItem still resolves its product after deletion', function () {
    $product = createTrashedProductFixture();
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $opener = User::factory()->create();
    $session = CountSession::create(['type' => 'main_store_stocktake', 'warehouse_id' => $warehouse->id, 'status' => 'counting', 'opened_by' => $opener->id, 'opened_at' => now()]);
    $item = CountSessionItem::create(['count_session_id' => $session->id, 'item_type' => 'product', 'product_id' => $product->id, 'expected_quantity_at_open' => 20]);

    expect($item->product?->name)->toBe('Origin Beer');
});

it('DamageReport still resolves its product after deletion', function () {
    $product = createTrashedProductFixture();
    $warehouse = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $reporter = User::factory()->create();
    $report = DamageReport::create(['product_id' => $product->id, 'quantity' => 1, 'warehouse_id' => $warehouse->id, 'reported_by' => $reporter->id, 'note' => 'Dropped']);

    expect($report->product?->name)->toBe('Origin Beer');
});

it('FridgeRestockMark still resolves its product after deletion', function () {
    $product = createTrashedProductFixture();
    $warehouse = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $marker = User::factory()->create();
    $mark = FridgeRestockMark::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'marked_quantity' => 6, 'marked_at' => now(), 'marked_by' => $marker->id]);

    expect($mark->product?->name)->toBe('Origin Beer');
});

it('ProcurementItem still resolves its product after deletion', function () {
    $product = createTrashedProductFixture();
    $warehouse = WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $recorder = User::factory()->create();
    $procurement = Procurement::create(['reference' => 'PRC-' . uniqid(), 'location_id' => $warehouse->id, 'purchased_at' => now(), 'recorded_by' => $recorder->id, 'total_cost' => 5000]);
    $txn = InventoryTransaction::create(['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'type' => 'purchase', 'quantity' => 10, 'user_id' => $recorder->id]);
    $item = ProcurementItem::create([
        'procurement_id' => $procurement->id, 'product_id' => $product->id, 'entered_qty' => 10, 'entered_unit' => 'base_unit',
        'base_qty' => 10, 'line_total_cost' => 5000, 'unit_cost' => 500, 'inventory_transaction_id' => $txn->id,
    ]);

    expect($item->product?->name)->toBe('Origin Beer');
});
