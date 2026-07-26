<?php

use App\Models\Booking;
use App\Models\Guest;
use App\Models\Room;
use App\Models\RoomSupply;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\RoomSupplyService;

function makeRoomSupplyFixture(): array
{
    $warehouse = WareHouse::create(['name' => 'Housekeeping Store', 'type' => 'storage']);
    $supply = RoomSupply::create(['name' => 'Tissue Roll', 'unit' => 'roll', 'cost_per_unit' => 100]);
    $user = User::factory()->create();

    $room = Room::create(['number' => '301', 'type' => 'Standard', 'price_per_night' => 10000, 'status' => 'available', 'housekeeping' => 'clean']);
    $guest = Guest::create(['name' => 'Room Supply Guest', 'phone' => '0802'.fake()->numerify('#######')]);
    $booking = Booking::create([
        'guest_id' => $guest->id, 'room_id' => $room->id,
        'check_in' => '2026-07-20', 'check_out' => '2026-07-22',
        'total_price' => 20000, 'nightly_rate' => 10000, 'status' => 'checked_in',
    ]);

    return compact('warehouse', 'supply', 'user', 'booking');
}

it('records a purchase, incrementing stock and updating the current cost per unit', function () {
    ['warehouse' => $warehouse, 'supply' => $supply, 'user' => $user] = makeRoomSupplyFixture();

    (new RoomSupplyService())->recordPurchase($supply, $warehouse, 50, 120, $user->id, 'PRC-1');

    expect((float) $supply->fresh()->current_stock)->toBe(50.0);
    expect((float) $supply->fresh()->cost_per_unit)->toBe(120.0);
    expect($supply->transactions()->where('type', 'purchase')->count())->toBe(1);
});

it('rejects a purchase of zero or negative quantity', function () {
    ['warehouse' => $warehouse, 'supply' => $supply, 'user' => $user] = makeRoomSupplyFixture();

    expect(fn () => (new RoomSupplyService())->recordPurchase($supply, $warehouse, 0, 100, $user->id))
        ->toThrow(RuntimeException::class);
});

it('records usage against a booking, deducting stock and snapshotting the cost at use', function () {
    ['warehouse' => $warehouse, 'supply' => $supply, 'user' => $user, 'booking' => $booking] = makeRoomSupplyFixture();

    $service = new RoomSupplyService();
    $service->recordPurchase($supply, $warehouse, 50, 100, $user->id);

    $usage = $service->recordUsage($booking, $supply, $warehouse, 4, $user->id);

    expect((float) $supply->fresh()->current_stock)->toBe(46.0);
    expect((float) $usage->quantity)->toBe(4.0);
    expect((float) $usage->unit_cost_at_use)->toBe(100.0);
    expect($usage->totalCost())->toBe(400.0);
    expect($usage->transaction->type)->toBe('usage');

    // Changing the catalog cost afterward must not rewrite the snapshot.
    $supply->update(['cost_per_unit' => 500]);
    expect((float) $usage->fresh()->unit_cost_at_use)->toBe(100.0);
});

it('refuses to record usage beyond what is actually in stock, naming the supply and warehouse', function () {
    ['warehouse' => $warehouse, 'supply' => $supply, 'user' => $user, 'booking' => $booking] = makeRoomSupplyFixture();

    $service = new RoomSupplyService();
    $service->recordPurchase($supply, $warehouse, 2, 100, $user->id);

    expect(fn () => $service->recordUsage($booking, $supply, $warehouse, 5, $user->id))
        ->toThrow(RuntimeException::class, "Not enough Tissue Roll in stock at Housekeeping Store — only 2 roll available.");

    expect((float) $supply->fresh()->current_stock)->toBe(2.0);
});
