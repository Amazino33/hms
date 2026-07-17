<?php

use App\Models\Booking;
use App\Models\Category;
use App\Models\DailyBusinessSnapshot;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Guest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Room;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\StaffDebtRepayment;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\Ceo\DailyMetricsService;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('computes revenue, cogs, and gross profit for a closed business day using unit_cost_at_sale', function () {
    CarbonImmutable::setTestNow('2026-07-16 12:00:00'); // noon WAT, safely inside the 2026-07-16 business day

    $warehouse = WareHouse::create(['name' => 'Bar '.uniqid(), 'type' => 'consumer', 'is_active' => 1]);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'cost_price' => 999, 'last_cost_price' => 200, 'category_id' => $category->id, 'is_active' => true]);
    $waiter = User::factory()->create();

    $order = Order::create(['order_number' => 'S-'.uniqid(), 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 1000, 'amount_paid' => 1000]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $beer->id, 'product_name' => 'Beer', 'item_type' => 'product', 'quantity' => 2, 'unit_price' => 500, 'subtotal' => 1000]);

    \App\Models\InventoryTransaction::create([
        'product_id' => $beer->id, 'warehouse_id' => $warehouse->id, 'type' => 'sale', 'quantity' => 2,
        'unit_cost_at_sale' => 200, 'reference' => "order:{$order->id}", 'user_id' => $waiter->id,
    ]);

    $data = (new DailyMetricsService())->forBusinessDate('2026-07-16');

    expect($data['revenue_earned_total'])->toBe(1000.0);
    expect($data['revenue_bar'])->toBe(1000.0);
    // 2 units at unit_cost_at_sale 200 (not the stale cost_price of 999).
    expect($data['cogs_total'])->toBe(400.0);
    expect($data['cogs_estimated_count'])->toBe(0);
    expect($data['gross_profit'])->toBe(600.0);
});

it('falls back to current cost and increments the estimated count when unit_cost_at_sale is missing', function () {
    CarbonImmutable::setTestNow('2026-07-16 12:00:00');

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'cost_price' => 150, 'category_id' => $category->id, 'is_active' => true]);
    $waiter = User::factory()->create();

    $order = Order::create(['order_number' => 'S-'.uniqid(), 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 500, 'amount_paid' => 500]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $beer->id, 'product_name' => 'Beer', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 500, 'subtotal' => 500]);
    // No matching InventoryTransaction — pre-Prompt-1-style history.

    $data = (new DailyMetricsService())->forBusinessDate('2026-07-16');

    expect($data['cogs_total'])->toBe(150.0); // falls back to current cost_price
    expect($data['cogs_estimated_count'])->toBe(1);
});

it('assigns a 2am sale to the previous business day, and a 5am sale to the same calendar day', function () {
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'cost_price' => 200, 'category_id' => $category->id, 'is_active' => true]);
    $waiter = User::factory()->create();

    // 2am WAT on the 17th = 1am UTC on the 17th = still business day "16th" (before the 4am WAT close).
    CarbonImmutable::setTestNow('2026-07-17 01:00:00');
    $lateOrder = Order::create(['order_number' => 'LATE-'.uniqid(), 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 500, 'amount_paid' => 500]);
    OrderItem::create(['order_id' => $lateOrder->id, 'product_id' => $beer->id, 'product_name' => 'Beer', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 500, 'subtotal' => 500]);

    // 5am WAT on the 17th = 4am UTC = business day "17th".
    CarbonImmutable::setTestNow('2026-07-17 04:00:00');
    $earlyOrder = Order::create(['order_number' => 'EARLY-'.uniqid(), 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 700, 'amount_paid' => 700]);
    OrderItem::create(['order_id' => $earlyOrder->id, 'product_id' => $beer->id, 'product_name' => 'Beer', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 700, 'subtotal' => 700]);

    $service = new DailyMetricsService();
    $day16 = $service->forBusinessDate('2026-07-16');
    $day17 = $service->forBusinessDate('2026-07-17');

    expect($day16['revenue_earned_total'])->toBe(500.0);
    expect($day17['revenue_earned_total'])->toBe(700.0);
});

it('reconstructs gap components as end-of-day positions, and gap components sum to gap total', function () {
    CarbonImmutable::setTestNow('2026-07-16 12:00:00');

    $waiter = User::factory()->create();
    $manager = User::factory()->create();

    // Unverified transfer, collected on the 16th.
    $order = Order::create(['order_number' => 'T-'.uniqid(), 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 1000, 'amount_paid' => 1000]);
    OrderPayment::create(['order_id' => $order->id, 'user_id' => $waiter->id, 'amount' => 1000, 'method' => 'transfer', 'verified' => false, 'paid_at' => now()]);

    // Staff debt incurred and partly repaid on the 16th.
    $debt = StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'shift_shortfall', 'amount' => 500, 'status' => 'open', 'created_by' => $manager->id]);
    StaffDebtRepayment::create(['staff_debt_id' => $debt->id, 'amount' => 200, 'method' => 'cash', 'recorded_by' => $manager->id]);

    // Unsettled shift that ended on the 16th.
    Shift::create([
        'user_id' => $waiter->id, 'type' => 'bartender', 'started_at' => now()->subHours(6), 'ended_at' => now(),
        'status' => 'awaiting_cashier', 'declared_cash' => 3000, 'declared_pos' => 1000,
    ]);

    $data = (new DailyMetricsService())->forBusinessDate('2026-07-16');

    expect($data['gap_unverified_transfers'])->toBe(1000.0);
    expect($data['gap_staff_debt_outstanding'])->toBe(300.0); // 500 - 200
    expect($data['gap_unsettled_shift_amount'])->toBe(4000.0); // 3000 + 1000
    expect($data['gap_total'])->toBe(
        $data['gap_unverified_transfers'] + $data['gap_open_folio_balance']
        + $data['gap_unsettled_shift_amount'] + $data['gap_staff_debt_outstanding']
    );

    expect($data['staff_debt_new'])->toBe(500.0);
    expect($data['staff_debt_repaid'])->toBe(200.0);
});

it('excludes a staff debt gap position once it has since been fully repaid, as of the snapshot day', function () {
    CarbonImmutable::setTestNow('2026-07-16 12:00:00');
    $waiter = User::factory()->create();
    $manager = User::factory()->create();

    $debt = StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'shift_shortfall', 'amount' => 500, 'status' => 'open', 'created_by' => $manager->id]);

    CarbonImmutable::setTestNow('2026-07-16 13:00:00'); // still the 16th
    StaffDebtRepayment::create(['staff_debt_id' => $debt->id, 'amount' => 500, 'method' => 'cash', 'recorded_by' => $manager->id]);

    $data = (new DailyMetricsService())->forBusinessDate('2026-07-16');

    expect($data['gap_staff_debt_outstanding'])->toBe(0.0);
});

it('sums non-voided expenses by date_incurred and excludes voided ones', function () {
    $expCategory = ExpenseCategory::create(['name' => 'Utilities', 'is_active' => true]);
    $user = User::factory()->create();

    $good = Expense::create(['amount' => 5000, 'expense_category_id' => $expCategory->id, 'date_incurred' => '2026-07-16', 'entered_by' => $user->id]);
    Expense::create(['amount' => 9999, 'expense_category_id' => $expCategory->id, 'date_incurred' => '2026-07-16', 'entered_by' => $user->id, 'voided_at' => now(), 'voided_by' => $user->id]);

    $data = (new DailyMetricsService())->forBusinessDate('2026-07-16');

    expect($data['expenses_total'])->toBe(5000.0);
});

it('the artisan command computes and stores an immutable snapshot row, and is idempotent by default', function () {
    Artisan::call('hms:compute-daily-snapshot', ['date' => '2026-07-10']);

    expect(DailyBusinessSnapshot::whereDate('business_date', '2026-07-10')->count())->toBe(1);

    Artisan::call('hms:compute-daily-snapshot', ['date' => '2026-07-10']);
    expect(DailyBusinessSnapshot::whereDate('business_date', '2026-07-10')->count())->toBe(1); // skipped, no duplicate
});

it('the artisan command inserts a superseding row instead of updating, with --force', function () {
    Artisan::call('hms:compute-daily-snapshot', ['date' => '2026-07-10']);
    $original = DailyBusinessSnapshot::whereDate('business_date', '2026-07-10')->first();

    Artisan::call('hms:compute-daily-snapshot', ['date' => '2026-07-10', '--force' => true]);

    expect(DailyBusinessSnapshot::whereDate('business_date', '2026-07-10')->count())->toBe(2);
    $latest = DailyBusinessSnapshot::latestFor('2026-07-10');
    expect($latest->id)->not->toBe($original->id);
    expect($latest->supersedes_id)->toBe($original->id);
    expect(DailyBusinessSnapshot::find($original->id)->business_date->toDateString())->toBe('2026-07-10'); // original untouched
});

it('refuses to snapshot the current or a future business day', function () {
    CarbonImmutable::setTestNow('2026-07-16 12:00:00');

    Artisan::call('hms:compute-daily-snapshot', ['date' => '2026-07-16']);
    expect(DailyBusinessSnapshot::whereDate('business_date', '2026-07-16')->exists())->toBeFalse();

    Artisan::call('hms:compute-daily-snapshot', ['date' => '2026-07-20']);
    expect(DailyBusinessSnapshot::whereDate('business_date', '2026-07-20')->exists())->toBeFalse();
});

it('backfills a date range', function () {
    Artisan::call('hms:compute-daily-snapshot', ['--from' => '2026-07-01', '--to' => '2026-07-03']);

    expect(DailyBusinessSnapshot::whereBetween('business_date', ['2026-07-01', '2026-07-03 23:59:59'])->count())->toBe(3);
});

it('live "today" and the snapshot the job would produce for the same closed day agree exactly', function () {
    CarbonImmutable::setTestNow('2026-07-16 12:00:00');

    $warehouse = WareHouse::create(['name' => 'Bar '.uniqid(), 'type' => 'consumer', 'is_active' => 1]);
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'cost_price' => 999, 'last_cost_price' => 200, 'category_id' => $category->id, 'is_active' => true]);
    $waiter = User::factory()->create();
    $order = Order::create(['order_number' => 'AGREE-'.uniqid(), 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 500, 'amount_paid' => 500]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $beer->id, 'product_name' => 'Beer', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 500, 'subtotal' => 500]);
    \App\Models\InventoryTransaction::create([
        'product_id' => $beer->id, 'warehouse_id' => $warehouse->id, 'type' => 'sale', 'quantity' => 1,
        'unit_cost_at_sale' => 200, 'reference' => "order:{$order->id}", 'user_id' => $waiter->id,
    ]);

    $liveToday = (new DailyMetricsService())->forBusinessDate('2026-07-16');

    // Move the clock forward past close, then run the same command the
    // scheduler would run for the day that just closed.
    CarbonImmutable::setTestNow('2026-07-17 05:00:00');
    Artisan::call('hms:compute-daily-snapshot', ['date' => '2026-07-16']);
    $snapshot = DailyBusinessSnapshot::latestFor('2026-07-16');

    expect((float) $snapshot->revenue_earned_total)->toBe($liveToday['revenue_earned_total']);
    expect((float) $snapshot->cogs_total)->toBe($liveToday['cogs_total']);
    expect((float) $snapshot->gross_profit)->toBe($liveToday['gross_profit']);
});

it('BusinessDay assigns pre-4am-WAT instants to the previous calendar day', function () {
    expect(BusinessDay::labelFor(CarbonImmutable::parse('2026-07-17 02:00:00')))->toBe('2026-07-16'); // 3am WAT
    expect(BusinessDay::labelFor(CarbonImmutable::parse('2026-07-17 03:00:00')))->toBe('2026-07-17'); // 4am WAT exactly
    expect(BusinessDay::labelFor(CarbonImmutable::parse('2026-07-17 12:00:00')))->toBe('2026-07-17');
});
