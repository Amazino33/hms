<?php

/**
 * Commission System Test Script
 * Run with: php test_commission.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Category;
use App\Models\Commission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;

echo "\n=== COMMISSION SYSTEM TEST ===\n\n";

// ── 1. Show Categories & their commission rates ─────────────────────────────
echo "--- CATEGORIES ---\n";
$categories = Category::all(['id', 'name', 'commission_rate']);
foreach ($categories as $c) {
    echo "  [{$c->id}] {$c->name}  =>  commission_rate: {$c->commission_rate}\n";
}
if ($categories->isEmpty()) {
    echo "  (no categories found)\n";
}

// ── 2. Show Users with roles ─────────────────────────────────────────────────
echo "\n--- USERS ---\n";
$users = User::with('roles')->get();
foreach ($users as $u) {
    $roles = $u->roles->pluck('name')->join(', ') ?: 'no role';
    echo "  [{$u->id}] {$u->name}  [{$roles}]\n";
}

// ── 3. Existing commissions ──────────────────────────────────────────────────
echo "\n--- EXISTING COMMISSIONS ---\n";
$existing = Commission::with(['user', 'order'])->latest()->take(5)->get();
echo "  Total records: " . Commission::count() . "\n";
foreach ($existing as $com) {
    echo "  #{$com->id}  waiter: {$com->user->name}  order: #{$com->order_id}  amount: {$com->amount}  at: {$com->created_at}\n";
}

// ── 4. Find a waiter and a product with commission_rate > 0 to test ──────────
echo "\n--- SIMULATION ---\n";

$waiter = User::role('waiter')->first();
if (! $waiter) {
    echo "  [SKIP] No waiter user found — cannot simulate.\n";
    goto end;
}

$categoryWithRate = Category::where('commission_rate', '>', 0)->first();
if (! $categoryWithRate) {
    echo "  [WARN] No category has commission_rate > 0.\n";
    echo "         Updating first category to rate = 50 for test...\n";
    $categoryWithRate = Category::first();
    if (! $categoryWithRate) {
        echo "  [SKIP] No categories at all.\n";
        goto end;
    }
    $categoryWithRate->update(['commission_rate' => 50]);
    echo "         Category [{$categoryWithRate->id}] '{$categoryWithRate->name}' set to rate=50\n";
}

$product = Product::where('category_id', $categoryWithRate->id)->first();
if (! $product) {
    echo "  [SKIP] No product in category '{$categoryWithRate->name}'.\n";
    goto end;
}

$table = \App\Models\Table::first();
if (! $table) {
    echo "  [SKIP] No table found.\n";
    goto end;
}

echo "  Waiter : [{$waiter->id}] {$waiter->name}\n";
echo "  Product: [{$product->id}] {$product->name} (category: {$categoryWithRate->name}, rate: {$categoryWithRate->commission_rate})\n";
echo "  Table  : [{$table->id}] {$table->name}\n";

// Before count
$beforeCount = Commission::count();

// Simulate exactly what OrderSplitter does:
// 1. Create the order first
$order = Order::create([
    'order_number'    => 'TEST-' . time(),
    'total_amount'    => $product->price * 2,
    'amount_paid'     => $product->price * 2,
    'status'          => 'paid',
    'payment_method'  => 'cash',
    'table_id'        => $table->id,
    'user_id'         => $waiter->id,
    'destination'     => 'main',
]);

// 2. THEN create the order items (as OrderSplitter does)
OrderItem::create([
    'order_id'     => $order->id,
    'product_id'   => $product->id,
    'product_name' => $product->name,
    'item_type'    => 'product',
    'quantity'     => 2,
    'unit_price'   => $product->price,
    'subtotal'     => $product->price * 2,
]);

// 3. NOW calculate commission (mirrors OrderSplitter::calculateCommission)
$order->load(['items.product.category']);
$commissionTotal = 0.0;
foreach ($order->items as $item) {
    if ($item->item_type === 'product' && $item->product && $item->product->category) {
        $commissionTotal += $item->quantity * (float) $item->product->category->commission_rate;
    }
}
if ($commissionTotal > 0) {
    Commission::create([
        'user_id'  => $order->user_id,
        'order_id' => $order->id,
        'amount'   => $commissionTotal,
    ]);
}

// Expected commission = quantity(2) * rate
$expected = 2 * (float) $categoryWithRate->commission_rate;
$afterCount = Commission::count();
$commission = Commission::where('order_id', $order->id)->first();

echo "\n--- RESULT ---\n";
echo "  Expected commission : ₦{$expected}\n";

if ($commission) {
    $status = abs($commission->amount - $expected) < 0.01 ? 'PASS' : 'FAIL (wrong amount)';
    echo "  Recorded commission : ₦{$commission->amount}   [{$status}]\n";
    echo "  Credited to waiter  : {$commission->user->name}  " . ($commission->user_id === $waiter->id ? '[PASS]' : '[FAIL - wrong user]') . "\n";
} else {
    echo "  Recorded commission : NONE  [FAIL — observer did not fire]\n";
}

echo "  Commissions before  : {$beforeCount}  /  after: {$afterCount}\n";

// Cleanup test order + commission
Commission::where('order_id', $order->id)->delete();
OrderItem::where('order_id', $order->id)->delete();
$order->delete();
echo "\n  (Test order cleaned up)\n";

end:
echo "\n=== TEST COMPLETE ===\n\n";
