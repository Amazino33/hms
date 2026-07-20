<?php

use App\Filament\Resources\Rooms\Pages\CreateRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Livewire\Livewire;

it('prefills price_per_night from the selected room type', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $deluxe = RoomType::create(['name' => 'Deluxe', 'price_per_night' => 25000, 'is_active' => true]);

    Livewire::actingAs($admin)
        ->test(CreateRoom::class)
        ->set('data.room_type_id', $deluxe->id)
        ->assertSet('data.price_per_night', '25000.00');
});

it('still lets the price be overridden after the type prefills it', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $deluxe = RoomType::create(['name' => 'Deluxe', 'price_per_night' => 25000, 'is_active' => true]);

    Livewire::actingAs($admin)
        ->test(CreateRoom::class)
        ->fillForm([
            'number' => '501',
            'room_type_id' => $deluxe->id,
            'status' => 'available',
            'housekeeping' => 'clean',
        ])
        ->set('data.price_per_night', 30000)
        ->call('create')
        ->assertHasNoFormErrors();

    $room = Room::where('number', '501')->first();
    expect($room)->not->toBeNull();
    expect((float) $room->price_per_night)->toEqual(30000.0);
    expect($room->room_type_id)->toBe($deluxe->id);
});

it('only offers active room types in the select', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    RoomType::create(['name' => 'Retired Type', 'price_per_night' => 10000, 'is_active' => false]);
    RoomType::create(['name' => 'Standard', 'price_per_night' => 15000, 'is_active' => true]);

    Livewire::actingAs($admin)
        ->test(CreateRoom::class)
        ->assertFormFieldExists('room_type_id')
        ->assertDontSee('Retired Type')
        ->assertSee('Standard');
});
