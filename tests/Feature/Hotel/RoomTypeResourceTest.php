<?php

use App\Filament\Resources\RoomTypes\Pages\CreateRoomType;
use App\Filament\Resources\RoomTypes\Pages\ListRoomTypes;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Livewire\Livewire;

it('lets super_admin create a room type with a price', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    Livewire::actingAs($admin)
        ->test(CreateRoomType::class)
        ->fillForm(['name' => 'Deluxe', 'price_per_night' => 25000, 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $type = RoomType::where('name', 'Deluxe')->first();
    expect($type)->not->toBeNull();
    expect((float) $type->price_per_night)->toEqual(25000.0);
});

it('denies a role with no RoomType permissions from viewing the list at all', function () {
    $this->seed(ShieldSeeder::class);
    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $response = $this->actingAs($manager)->get('/admin/room-types');

    $response->assertForbidden();
});

it('does not offer a delete action anywhere for a room type', function () {
    $this->seed(ShieldSeeder::class);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $type = RoomType::create(['name' => 'Suite', 'price_per_night' => 40000, 'is_active' => true]);

    Livewire::actingAs($admin)
        ->test(ListRoomTypes::class)
        ->assertActionDoesNotExist('delete');

    expect(RoomType::find($type->id))->not->toBeNull();
});
