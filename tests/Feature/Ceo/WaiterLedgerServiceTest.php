<?php

use App\Models\Commission;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\StaffDebtRepayment;
use App\Models\User;
use App\Services\Ceo\DateRange;
use App\Services\Ceo\WaiterLedgerService;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('includes commission earned on the shift, order, summary, and all-waiters rows', function () {
    CarbonImmutable::setTestNow('2026-07-15 12:00:00');
    Role::firstOrCreate(['name' => 'waiter']);
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    $shift = Shift::create([
        'user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now()->subHours(3),
        'ended_at' => now(), 'status' => 'confirmed',
    ]);

    $order = Order::create([
        'order_number' => 'ORD-' . uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id,
        'status' => 'paid', 'total_amount' => 1000, 'amount_paid' => 1000,
    ]);
    OrderItem::create(['order_id' => $order->id, 'product_name' => 'Beer', 'item_type' => 'product', 'quantity' => 2, 'unit_price' => 500, 'subtotal' => 1000]);
    Commission::create(['user_id' => $waiter->id, 'order_id' => $order->id, 'amount' => 50]);

    $range = new DateRange(CarbonImmutable::today(), CarbonImmutable::today());
    $service = new WaiterLedgerService();

    $shiftRows = $service->perShiftRows($waiter->id, $range);
    expect($shiftRows->first()['commission'])->toBe(50.0);

    $orderRows = $service->orderRows($waiter->id, $range);
    expect($orderRows)->toHaveCount(1);
    expect($orderRows->first()['commission'])->toBe(50.0);
    expect($orderRows->first()['order_number'])->toBe($order->order_number);

    $summary = $service->summary($waiter->id, $range);
    expect($summary['total_commission_earned'])->toBe(50.0);

    $allWaiters = $service->allWaiters($range)->firstWhere('waiter_id', $waiter->id);
    expect($allWaiters['commission_earned'])->toBe(50.0);
});

it('lists every debt reason in debtRows, not only shift_shortfall', function () {
    CarbonImmutable::setTestNow('2026-07-15 12:00:00');
    $waiter = User::factory()->create();
    $manager = User::factory()->create();

    $shortfall = StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'shift_shortfall', 'amount' => 100, 'status' => 'open', 'created_by' => $manager->id]);
    StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'manual', 'amount' => 200, 'status' => 'open', 'created_by' => $manager->id]);
    StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'unpaid_order_conversion', 'amount' => 300, 'status' => 'open', 'created_by' => $manager->id]);
    StaffDebtRepayment::create(['staff_debt_id' => $shortfall->id, 'amount' => 40, 'method' => 'cash', 'recorded_by' => $manager->id]);

    $range = new DateRange(CarbonImmutable::today(), CarbonImmutable::today());
    $rows = (new WaiterLedgerService())->debtRows($waiter->id, $range);

    expect($rows)->toHaveCount(3);
    expect($rows->sum('amount'))->toBe(600.0);

    $shortfallRow = $rows->firstWhere('reason', 'shift_shortfall');
    expect($shortfallRow['repaid'])->toBe(40.0);
    expect($shortfallRow['remaining'])->toBe(60.0);

    $summary = (new WaiterLedgerService())->summary($waiter->id, $range);
    expect($summary['debt_incurred_in_period'])->toBe(600.0);
});

it('agrees on sales and shortfall between the single-waiter shift view and the all-waiters overview for the same range', function () {
    CarbonImmutable::setTestNow('2026-07-15 12:00:00');
    Role::firstOrCreate(['name' => 'waiter']);
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    $shift = Shift::create([
        'user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now()->subHours(3),
        'ended_at' => now(), 'status' => 'confirmed',
    ]);

    $order = Order::create([
        'order_number' => 'ORD-' . uniqid(), 'shift_id' => $shift->id, 'user_id' => $waiter->id,
        'status' => 'paid', 'total_amount' => 2000, 'amount_paid' => 2000,
    ]);
    OrderItem::create(['order_id' => $order->id, 'product_name' => 'Wine', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 2000, 'subtotal' => 2000]);
    StaffDebt::create(['user_id' => $waiter->id, 'shift_id' => $shift->id, 'reason' => 'shift_shortfall', 'amount' => 100, 'status' => 'open', 'created_by' => $waiter->id]);

    $range = new DateRange(CarbonImmutable::today(), CarbonImmutable::today());
    $service = new WaiterLedgerService();

    $singleWaiterSales = $service->perShiftRows($waiter->id, $range)->sum('total_sales');
    $singleWaiterShortfall = $service->perShiftRows($waiter->id, $range)->sum('shortfall');

    $overviewRow = $service->allWaiters($range)->firstWhere('waiter_id', $waiter->id);

    expect($overviewRow['sales_handled'])->toBe($singleWaiterSales);
    expect($overviewRow['shortfall'])->toBe($singleWaiterShortfall);
});
