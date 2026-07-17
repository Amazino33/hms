<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Services\Ceo\DateRange;
use App\Services\Ceo\RevenueReportService;
use App\Services\ReceptionistShiftService;
use App\Services\ReservationService;
use App\Services\RoomOrderService;
use Carbon\CarbonImmutable;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('attributes a folio-billed food item to Restaurant (what-was-sold) and flags it billed via folio (secondary dimension)', function () {
    // Pinned to a WAT daytime hour: a single-day DateRange resolves its
    // boundary via BusinessDay's 4am WAT cutoff (see DateRange), so an
    // unpinned "now" run between 00:00-02:59 UTC would fall before that
    // day's business-day open and be excluded from its own range.
    CarbonImmutable::setTestNow('2026-07-15 12:00:00');
    $room = Room::create(['number' => '901', 'type' => 'Deluxe', 'price_per_night' => 20000, 'status' => 'available', 'housekeeping' => 'clean']);
    $receptionist = User::factory()->create();
    (new ReceptionistShiftService())->startShift($receptionist, 0);

    $category = Category::create(['name' => 'Food', 'type' => 'food']);
    $jollof = Product::create(['name' => 'Jollof Rice', 'price' => 3000, 'cost_price' => 1000, 'category_id' => $category->id, 'is_active' => true]);

    // A food item routes to the kitchen, which requires an active chef
    // shift to accept the order at all.
    $chef = User::factory()->create();
    Shift::create(['user_id' => $chef->id, 'type' => 'chef', 'started_at' => now(), 'status' => 'active']);

    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Attribution Guest', 'guest_phone' => '0812' . fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => null,
    ], $receptionist->id);

    $booking = (new \App\Services\BookingService())->checkIn($booking, $receptionist->id);

    (new RoomOrderService())->placeOrder($room->id, [
        (string) $jollof->id => ['name' => $jollof->name, 'price' => 3000, 'quantity' => 1],
    ], $receptionist->id);

    $range = new DateRange(CarbonImmutable::today(), CarbonImmutable::today());
    $service = new RevenueReportService();
    $lineItems = $service->lineItems($range);

    $row = $lineItems->firstWhere('item_name', 'Jollof Rice');

    expect($row)->not->toBeNull();
    expect($row['source'])->toBe('restaurant');
    expect($row['billed_via_folio'])->toBeTrue();

    $mix = $service->revenueMix($range);
    expect($mix['restaurant'])->toBe(3000.0);
});

it('does not flag a direct (non-room) order as billed via folio', function () {
    CarbonImmutable::setTestNow('2026-07-15 12:00:00'); // see note in the test above
    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Star Beer', 'price' => 700, 'cost_price' => 300, 'category_id' => $category->id, 'is_active' => true]);

    $waiter = User::factory()->create();
    $order = \App\Models\Order::create(['order_number' => 'D-' . uniqid(), 'user_id' => $waiter->id, 'status' => 'paid', 'total_amount' => 700, 'amount_paid' => 700]);
    \App\Models\OrderItem::create(['order_id' => $order->id, 'product_id' => $beer->id, 'product_name' => 'Star Beer', 'item_type' => 'product', 'quantity' => 1, 'unit_price' => 700, 'subtotal' => 700]);

    $range = new DateRange(CarbonImmutable::today(), CarbonImmutable::today());
    $row = (new RevenueReportService())->lineItems($range)->firstWhere('item_name', 'Star Beer');

    expect($row['billed_via_folio'])->toBeFalse();
    expect($row['source'])->toBe('bar');
});
