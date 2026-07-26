<?php

use App\Filament\Ceo\Pages\ReportExplorer;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

afterEach(function () {
    CarbonImmutable::setTestNow();
    Cache::forget('setting:room_generator_cost_per_night');
    Cache::forget('setting:room_electricity_cost_per_night');
});

it('shows the room profit section on the rooms tab of the report explorer', function () {
    CarbonImmutable::setTestNow('2026-07-16 12:00:00');
    Role::firstOrCreate(['name' => 'ceo']);
    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('ceo'));

    SettingsService::set('room_generator_cost_per_night', '200', 'string', $ceo->id);

    $room = Room::create(['number' => '701', 'type' => 'Standard', 'price_per_night' => 12000, 'status' => 'available', 'housekeeping' => 'clean']);
    $guest = Guest::create(['name' => 'Explorer Guest', 'phone' => '0807'.fake()->numerify('#######')]);
    Booking::create([
        'guest_id' => $guest->id, 'room_id' => $room->id,
        'check_in' => '2026-07-16', 'check_out' => '2026-07-17',
        'total_price' => 12000, 'nightly_rate' => 12000, 'status' => 'checked_in',
    ]);

    $component = Livewire::actingAs($ceo)->test(ReportExplorer::class, ['tab' => 'rooms']);
    $data = $component->instance()->tabData();

    expect($data['profit']['revenue'])->toBe(12000.0);
    expect($data['profit']['power_cost'])->toBe(200.0);
    expect($data['profit']['profit'])->toBe(11800.0);
});
