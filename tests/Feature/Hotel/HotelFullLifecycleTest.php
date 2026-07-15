<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\BookingService;
use App\Services\CashierSettlementService;
use App\Services\FolioService;
use App\Services\InventoryService;
use App\Services\PorterDeliveryService;
use App\Services\ReceptionistShiftService;
use App\Services\ReservationService;
use App\Services\RoomOrderService;

/**
 * The capstone test for the whole hotel module: one guest's stay walked
 * through every step in order — reserve, check in, order a room-service
 * beer, the bar makes it ready (stock deducts exactly here, not at order
 * time), a porter picks it up and delivers it, the guest settles the
 * bill, checks out (sealed snapshot), and the receptionist's shift closes
 * clean. Each step re-touches state the previous step left behind, so
 * this is the one test that would catch a regression at a step boundary
 * that no single step's own test file would.
 */
it('walks one guest through reservation, stay, room order, and checkout, with a clean shift settlement', function () {
    // ── Setup: room, receptionist (with a till float), bar stock ──────
    $room = Room::create(['number' => '1101', 'type' => 'Deluxe', 'price_per_night' => 20000, 'status' => 'available', 'housekeeping' => 'clean']);
    $receptionist = User::factory()->create();
    $shift = (new ReceptionistShiftService())->startShift($receptionist, 5000);

    $category = Category::create(['name' => 'Drinks', 'type' => 'drink']);
    $beer = Product::create(['name' => 'Lifecycle Beer', 'price' => 1000, 'category_id' => $category->id, 'is_active' => true]);
    WareHouse::firstOrCreate(['id' => 4], ['name' => 'Bar', 'type' => 'consumer']);
    InventoryItem::create(['product_id' => $beer->id, 'warehouse_id' => 4, 'quantity' => 10]);
    $bartender = User::factory()->create();
    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    // ── 1. Reserve, with a deposit ─────────────────────────────────────
    $booking = (new ReservationService())->createReservation([
        'room_id' => $room->id, 'guest_name' => 'Lifecycle Guest', 'guest_phone' => '0817' . fake()->numerify('#######'),
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'deposit' => 3000,
    ], $receptionist->id);
    expect($booking->status)->toBe('reserved');
    expect($room->fresh()->occupancyState())->toBe('arriving_today');

    // ── 2. Check in — posts the room charge ─────────────────────────────
    $booking = (new BookingService())->checkIn($booking, $receptionist->id);
    expect($booking->status)->toBe('checked_in');
    expect($room->fresh()->occupancyState())->toBe('occupied');
    // Deposit (-3000) + room charge (+20000) = 17000 owed.
    expect($booking->folio->balance())->toBe(17000.0);

    // ── 3. Room order — billed immediately, stock NOT yet deducted ─────
    $stockBefore = (int) InventoryItem::where('product_id', $beer->id)->where('warehouse_id', 4)->value('quantity');
    $orders = (new RoomOrderService())->placeOrder($room->id, [
        (string) $beer->id => ['name' => $beer->name, 'price' => 1000, 'quantity' => 2],
    ], $receptionist->id);
    $order = $orders[0];
    expect($order->origin_label)->toBe('Room 1101');
    expect((int) InventoryItem::where('product_id', $beer->id)->where('warehouse_id', 4)->value('quantity'))->toBe($stockBefore);
    expect($booking->folio->fresh()->balance())->toBe(19000.0); // +2000 for the beers

    // ── 4. Bar marks it ready — stock deducts exactly now ───────────────
    $order->update(['status' => 'ready']); // simulating BarDisplay::markAsReady()'s status flip
    InventoryService::deductInventoryForOrderItems($order->fresh(['items']));
    expect((int) InventoryItem::where('product_id', $beer->id)->where('warehouse_id', 4)->value('quantity'))->toBe($stockBefore - 2);

    // ── 5. Porter picks up and delivers ─────────────────────────────────
    $porter = User::factory()->create();
    $picked = (new PorterDeliveryService())->pickUp($order->fresh(), $porter);
    expect($picked->picked_up_by)->toBe($porter->id);
    $delivered = (new PorterDeliveryService())->confirmDelivered($picked, $porter);
    expect($delivered->status)->toBe('served');

    // ── 6. Guest settles the full outstanding balance in cash ──────────
    $outstanding = $booking->folio->fresh()->balance();
    (new FolioService())->recordPayment($booking->folio, $outstanding, 'cash', null, $receptionist->id);
    expect($booking->folio->fresh()->balance())->toBe(0.0);

    // ── 7. Hard checkout gate — now passes, seals a snapshot ───────────
    $checkedOut = (new BookingService())->checkOut($booking->fresh(), $receptionist->id);
    expect($checkedOut->status)->toBe('checked_out');
    expect($checkedOut->checkout_snapshot['balance'])->toBeLessThanOrEqual(0.0);
    expect($room->fresh()->occupancyState())->toBe('vacant');

    // Sealed: no further charge can be posted.
    expect(fn () => (new FolioService())->postIncidental($booking->folio->fresh(), 'Late fee', 100, $receptionist->id))
        ->toThrow(Exception::class);

    // ── 8. Receptionist closes their shift; cashier confirms clean ─────
    // Expected cash = 5000 float + 3000 deposit + (outstanding settlement, which was cash).
    $expectedCash = (new ReceptionistShiftService())->expectedCashRemittance($shift->fresh());
    expect($expectedCash)->toBe(5000.0 + 3000.0 + $outstanding);

    (new ReceptionistShiftService())->declareEnd($shift->fresh(), $expectedCash, 0);
    $cashier = User::factory()->create();
    $settlement = new CashierSettlementService();
    $settlement->confirmCash($shift->fresh(), $expectedCash, $cashier->id);
    $confirmed = $settlement->confirmPos($shift->fresh(), 0, $cashier->id);

    expect(\App\Models\StaffDebt::where('shift_id', $shift->id)->exists())->toBeFalse(); // declared exactly what was expected — no shortfall
    expect($confirmed->status)->toBe('confirmed');
});
