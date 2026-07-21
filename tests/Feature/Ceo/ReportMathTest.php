<?php

use App\Models\Booking;
use App\Models\Category;
use App\Models\Guest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Room;
use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\StaffDebtRepayment;
use App\Models\User;
use App\Services\Ceo\DateRange;
use App\Services\Ceo\LeakageReportService;
use App\Services\Ceo\OccupancyReportService;
use App\Services\Ceo\RevenueReportService;
use App\Services\Ceo\WaiterLedgerService;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('computes shift shortfall rate as shortfall over that shift\'s total sales', function () {
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

    StaffDebt::create(['user_id' => $waiter->id, 'shift_id' => $shift->id, 'reason' => 'shift_shortfall', 'amount' => 250, 'status' => 'open', 'created_by' => $waiter->id]);

    $range = new DateRange(CarbonImmutable::today()->subDay(), CarbonImmutable::today()->addDay());
    $rows = (new WaiterLedgerService())->perShiftRows($waiter->id, $range);

    expect($rows)->toHaveCount(1);
    expect($rows->first()['total_sales'])->toBe(1000.0);
    expect($rows->first()['shortfall'])->toBe(250.0);
    expect($rows->first()['shortfall_rate_pct'])->toBe(25.0);
});

it('computes revenue contribution percent and margin percent per product', function () {
    // Pinned to a WAT daytime hour: a single-day DateRange resolves its
    // boundary via BusinessDay's 9am WAT cutoff (see DateRange), so an
    // unpinned "now" run before that hour would fall before that day's
    // business-day open and be excluded from its own range.
    CarbonImmutable::setTestNow('2026-07-15 12:00:00');
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Beer', 'price' => 500, 'cost_price' => 200, 'category_id' => $category->id, 'is_active' => true]);
    $coke = Product::create(['name' => 'Coke', 'price' => 300, 'cost_price' => 100, 'category_id' => $category->id, 'is_active' => true]);

    $waiter = User::factory()->create();
    $order1 = Order::create(['order_number' => 'A-' . uniqid(), 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 1500, 'amount_paid' => 1500]);
    OrderItem::create(['order_id' => $order1->id, 'product_id' => $beer->id, 'product_name' => 'Beer', 'item_type' => 'product', 'quantity' => 3, 'unit_price' => 500, 'subtotal' => 1500]);

    $order2 = Order::create(['order_number' => 'B-' . uniqid(), 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 300, 'amount_paid' => 300]);
    OrderItem::create(['order_id' => $order2->id, 'product_id' => $coke->id, 'product_name' => 'Coke', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 300, 'subtotal' => 300]);

    $range = new DateRange(CarbonImmutable::today(), CarbonImmutable::today());
    $service = new RevenueReportService();
    $breakdown = $service->productBreakdown($service->lineItems($range));

    $beerRow = $breakdown->firstWhere('item_name', 'Beer');
    $cokeRow = $breakdown->firstWhere('item_name', 'Coke');

    // Total revenue = 1800; Beer = 1500 -> 83.33%, Coke = 300 -> 16.67%
    expect($beerRow['revenue_contribution_pct'])->toBe(83.33);
    expect($cokeRow['revenue_contribution_pct'])->toBe(16.67);

    // Beer: revenue 1500, cost 200*3=600, margin 900, margin% = 60
    expect($beerRow['margin'])->toBe(900.0);
    expect($beerRow['margin_pct'])->toBe(60.0);
    expect($beerRow['source'])->toBe('bar');
});

it('folds a service-type category into Restaurant revenue', function () {
    CarbonImmutable::setTestNow('2026-07-15 12:00:00'); // see note above
    $category = Category::create(['name' => 'Spa', 'type' => 'service']);
    $product = Product::create(['name' => 'Massage', 'price' => 5000, 'cost_price' => 0, 'category_id' => $category->id, 'is_active' => true]);

    $waiter = User::factory()->create();
    $order = Order::create(['order_number' => 'S-' . uniqid(), 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 5000, 'amount_paid' => 5000]);
    OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Massage', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 5000, 'subtotal' => 5000]);

    $range = new DateRange(CarbonImmutable::today(), CarbonImmutable::today());
    $mix = (new RevenueReportService())->revenueMix($range);

    expect($mix['restaurant'])->toBe(5000.0);
    expect($mix['bar'])->toBe(0.0);
});

it('computes nights-based occupancy correctly, excluding the checkout morning night, plus ADR and RevPAR', function () {
    $room1 = Room::create(['number' => '101', 'type' => 'Standard', 'price_per_night' => 10000, 'status' => 'available', 'housekeeping' => 'clean']);
    Room::create(['number' => '102', 'type' => 'Standard', 'price_per_night' => 10000, 'status' => 'available', 'housekeeping' => 'clean']);
    $guest = Guest::create(['name' => 'Occupancy Guest', 'phone' => '0800' . fake()->numerify('#######')]);

    // 3 nights: 15th, 16th, 17th — the 18th (checkout morning) must NOT count.
    Booking::create([
        'guest_id' => $guest->id, 'room_id' => $room1->id,
        'check_in' => '2026-07-15', 'check_out' => '2026-07-18',
        'total_price' => 30000, 'nightly_rate' => 10000, 'status' => 'checked_in',
        'checked_in_at' => '2026-07-15 12:00:00', 'checked_out_at' => '2026-07-18 09:00:00',
    ]);

    $range = new DateRange(CarbonImmutable::parse('2026-07-15'), CarbonImmutable::parse('2026-07-18'));
    $service = new OccupancyReportService();
    $breakdown = $service->nightlyBreakdown($range)->keyBy(fn ($d) => $d['date']->toDateString());

    expect($breakdown['2026-07-15']['rooms_occupied'])->toBe(1);
    expect($breakdown['2026-07-16']['rooms_occupied'])->toBe(1);
    expect($breakdown['2026-07-17']['rooms_occupied'])->toBe(1);
    expect($breakdown['2026-07-18']['rooms_occupied'])->toBe(0);
    expect($breakdown['2026-07-18']['arrivals'])->toBe(0);
    expect($breakdown['2026-07-18']['departures'])->toBe(1);
    expect($breakdown['2026-07-15']['arrivals'])->toBe(1);

    $summary = $service->summary($range);
    // 2 rooms x 4 days in range = 8 room-nights available; 3 sold.
    expect($summary['room_nights_available'])->toBe(8);
    expect($summary['room_nights_sold'])->toBe(3);
    expect($summary['total_room_revenue'])->toBe(30000.0);
    expect($summary['adr'])->toBe(10000.0); // 30000 / 3
    expect($summary['revpar'])->toBe(3750.0); // 30000 / 8
});

it('excludes cancelled and no_show bookings from occupancy entirely', function () {
    $room = Room::create(['number' => '201', 'type' => 'Standard', 'price_per_night' => 8000, 'status' => 'available', 'housekeeping' => 'clean']);
    $guest = Guest::create(['name' => 'Cancelled Guest', 'phone' => '0801' . fake()->numerify('#######')]);

    Booking::create([
        'guest_id' => $guest->id, 'room_id' => $room->id,
        'check_in' => '2026-08-01', 'check_out' => '2026-08-03',
        'total_price' => 16000, 'nightly_rate' => 8000, 'status' => 'cancelled',
    ]);

    $range = new DateRange(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-03'));
    $summary = (new OccupancyReportService())->summary($range);

    expect($summary['room_nights_sold'])->toBe(0);
    expect($summary['total_room_revenue'])->toBe(0.0);
});

it('buckets outstanding debt into 0-7 / 8-30 / 30+ day aging correctly', function () {
    CarbonImmutable::setTestNow('2026-07-20');
    $waiter = User::factory()->create();

    StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'shift_shortfall', 'amount' => 100, 'status' => 'open', 'created_by' => $waiter->id, 'created_at' => CarbonImmutable::parse('2026-07-18')]); // 2 days
    StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'shift_shortfall', 'amount' => 200, 'status' => 'open', 'created_by' => $waiter->id, 'created_at' => CarbonImmutable::parse('2026-07-05')]); // 15 days
    StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'shift_shortfall', 'amount' => 300, 'status' => 'open', 'created_by' => $waiter->id, 'created_at' => CarbonImmutable::parse('2026-05-01')]); // 80 days

    $aging = (new LeakageReportService())->currentAgingBreakdown();

    expect($aging['aging_0_7'])->toBe(100.0);
    expect($aging['aging_8_30'])->toBe(200.0);
    expect($aging['aging_30_plus'])->toBe(300.0);
});

it('computes repayment ratio as repaid over incurred for the period', function () {
    CarbonImmutable::setTestNow('2026-07-15 12:00:00'); // see note in the revenue-contribution test above
    $waiter = User::factory()->create();
    $manager = User::factory()->create();

    $debt = StaffDebt::create(['user_id' => $waiter->id, 'reason' => 'shift_shortfall', 'amount' => 1000, 'status' => 'open', 'created_by' => $manager->id]);
    StaffDebtRepayment::create(['staff_debt_id' => $debt->id, 'amount' => 400, 'method' => 'cash', 'recorded_by' => $manager->id]);
    $debt->refreshStatus();

    $range = new DateRange(CarbonImmutable::today(), CarbonImmutable::today());
    $summary = (new LeakageReportService())->summary($range);

    expect($summary['total_incurred'])->toBe(1000.0);
    expect($summary['total_repaid'])->toBe(400.0);
    expect($summary['repayment_ratio_pct'])->toBe(40.0);
    expect($summary['total_outstanding_now'])->toBe(600.0);
});
