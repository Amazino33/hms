<?php

use App\Models\Booking;
use App\Models\Guest;
use App\Models\Room;
use App\Models\RoomSupply;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\Ceo\DateRange;
use App\Services\Ceo\RoomProfitService;
use App\Services\RoomSupplyService;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

afterEach(function () {
    CarbonImmutable::setTestNow();
    Cache::forget('setting:room_generator_cost_per_night');
    Cache::forget('setting:room_electricity_cost_per_night');
});

it('computes a booking\'s profit as revenue minus flat power cost minus recorded supplies cost', function () {
    $user = User::factory()->create();
    SettingsService::set('room_generator_cost_per_night', '500', 'string', $user->id);
    SettingsService::set('room_electricity_cost_per_night', '300', 'string', $user->id);

    $room = Room::create(['number' => '401', 'type' => 'Standard', 'price_per_night' => 10000, 'status' => 'available', 'housekeeping' => 'clean']);
    $guest = Guest::create(['name' => 'Profit Guest', 'phone' => '0803'.fake()->numerify('#######')]);
    $booking = Booking::create([
        'guest_id' => $guest->id, 'room_id' => $room->id,
        'check_in' => '2026-07-20', 'check_out' => '2026-07-23', // 3 nights
        'total_price' => 30000, 'nightly_rate' => 10000, 'status' => 'checked_in',
    ]);

    $warehouse = WareHouse::create(['name' => 'Housekeeping Store', 'type' => 'storage']);
    $supply = RoomSupply::create(['name' => 'Soap', 'unit' => 'bar', 'cost_per_unit' => 200]);
    $roomSupplyService = new RoomSupplyService();
    $roomSupplyService->recordPurchase($supply, $warehouse, 10, 200, $user->id);
    $roomSupplyService->recordUsage($booking, $supply, $warehouse, 3, $user->id); // 600

    $result = (new RoomProfitService())->forBooking($booking);

    expect($result['nights'])->toBe(3);
    expect($result['revenue'])->toBe(30000.0);
    expect($result['power_cost'])->toBe(2400.0); // (500+300) * 3
    expect($result['supplies_cost'])->toBe(600.0);
    expect($result['total_cost'])->toBe(3000.0);
    expect($result['profit'])->toBe(27000.0);
});

it('defaults power cost to zero when no settings have been configured', function () {
    $room = Room::create(['number' => '402', 'type' => 'Standard', 'price_per_night' => 5000, 'status' => 'available', 'housekeeping' => 'clean']);
    $guest = Guest::create(['name' => 'No Settings Guest', 'phone' => '0804'.fake()->numerify('#######')]);
    $booking = Booking::create([
        'guest_id' => $guest->id, 'room_id' => $room->id,
        'check_in' => '2026-07-20', 'check_out' => '2026-07-21',
        'total_price' => 5000, 'nightly_rate' => 5000, 'status' => 'checked_in',
    ]);

    $result = (new RoomProfitService())->forBooking($booking);

    expect($result['power_cost'])->toBe(0.0);
    expect($result['supplies_cost'])->toBe(0.0);
    expect($result['profit'])->toBe(5000.0);
});

it('aggregates profit for a date range using the same occupancy figures the dashboard uses', function () {
    $user = User::factory()->create();
    SettingsService::set('room_generator_cost_per_night', '100', 'string', $user->id);
    SettingsService::set('room_electricity_cost_per_night', '50', 'string', $user->id);

    $room = Room::create(['number' => '501', 'type' => 'Standard', 'price_per_night' => 10000, 'status' => 'available', 'housekeeping' => 'clean']);
    $guest = Guest::create(['name' => 'Range Guest', 'phone' => '0805'.fake()->numerify('#######')]);
    Booking::create([
        'guest_id' => $guest->id, 'room_id' => $room->id,
        'check_in' => '2026-07-15', 'check_out' => '2026-07-18', // 3 nights: 15,16,17
        'total_price' => 30000, 'nightly_rate' => 10000, 'status' => 'checked_in',
        'checked_in_at' => '2026-07-15 12:00:00', 'checked_out_at' => '2026-07-18 09:00:00',
    ]);

    $range = new DateRange(CarbonImmutable::parse('2026-07-15'), CarbonImmutable::parse('2026-07-18'));
    $summary = (new RoomProfitService())->summary($range);

    expect($summary['room_nights_sold'])->toBe(3);
    expect($summary['revenue'])->toBe(30000.0);
    expect($summary['power_cost'])->toBe(450.0); // (100+50) * 3
    expect($summary['supplies_cost'])->toBe(0.0);
    expect($summary['profit'])->toBe(29550.0);
});
