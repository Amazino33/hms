<?php

use App\Filament\Pages\ReservationsTimeline;
use App\Models\Room;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function makeNightsFieldRoom(string $number = '401'): Room
{
    return Room::create(['number' => $number, 'type' => 'Standard', 'price_per_night' => 20000, 'status' => 'available', 'housekeeping' => 'clean']);
}

it('defaults nights to 1 and check-out to one day after check-in when opening the form', function () {
    $room = makeNightsFieldRoom();
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $checkIn = now()->toDateString();

    $component = Livewire::actingAs($admin)
        ->test(ReservationsTimeline::class)
        ->call('openForm', $room->id, $checkIn);

    expect($component->get('nights'))->toBe(1);
    expect($component->get('checkOut'))->toBe(now()->addDay()->toDateString());
});

it('recomputes check-out when nights is changed', function () {
    $room = makeNightsFieldRoom();
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $checkIn = now()->toDateString();

    $component = Livewire::actingAs($admin)
        ->test(ReservationsTimeline::class)
        ->call('openForm', $room->id, $checkIn)
        ->set('nights', 5);

    expect($component->get('checkOut'))->toBe(now()->addDays(5)->toDateString());
});

it('recomputes check-out when check-in changes, keeping the nights count the same', function () {
    $room = makeNightsFieldRoom();
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $component = Livewire::actingAs($admin)
        ->test(ReservationsTimeline::class)
        ->call('openForm', $room->id, now()->toDateString())
        ->set('nights', 3)
        ->set('checkIn', now()->addDays(2)->toDateString());

    expect($component->get('nights'))->toBe(3);
    expect($component->get('checkOut'))->toBe(now()->addDays(5)->toDateString());
});

it('recomputes nights when check-out is picked directly', function () {
    $room = makeNightsFieldRoom();
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $checkIn = now()->toDateString();

    $component = Livewire::actingAs($admin)
        ->test(ReservationsTimeline::class)
        ->call('openForm', $room->id, $checkIn)
        ->set('checkOut', now()->addDays(7)->toDateString());

    expect($component->get('nights'))->toBe(7);
});

it('clamps nights to at least 1 rather than allowing zero or negative', function () {
    $room = makeNightsFieldRoom();
    $admin = User::factory()->create();
    $admin->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    $component = Livewire::actingAs($admin)
        ->test(ReservationsTimeline::class)
        ->call('openForm', $room->id, now()->toDateString())
        ->set('nights', 0);

    expect($component->get('nights'))->toBe(1);
});
