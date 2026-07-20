<?php

use App\Filament\Pages\MyStoreCount;
use App\Models\CountSession;
use App\Models\User;
use App\Models\WareHouse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

it('opens a products-only session when Count Products is clicked', function () {
    WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    Livewire::actingAs($storekeeper)
        ->test(MyStoreCount::class)
        ->call('startCount', 'product');

    $session = CountSession::where('opened_by', $storekeeper->id)->first();
    expect($session)->not->toBeNull();
    expect($session->item_scope)->toBe('product');
});

it('opens an ingredients-only session when Count Ingredients is clicked', function () {
    WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    Livewire::actingAs($storekeeper)
        ->test(MyStoreCount::class)
        ->call('startCount', 'ingredient');

    $session = CountSession::where('opened_by', $storekeeper->id)->first();
    expect($session)->not->toBeNull();
    expect($session->item_scope)->toBe('ingredient');
});

it('shows which scope is in progress when continuing an open count', function () {
    WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    Livewire::actingAs($storekeeper)
        ->test(MyStoreCount::class)
        ->call('startCount', 'ingredient');

    Livewire::actingAs($storekeeper)
        ->test(MyStoreCount::class)
        ->assertSee('Ingredients')
        ->assertSee('Continue Counting');
});

it('ignores an invalid scope value instead of opening a session', function () {
    WareHouse::create(['name' => 'Main Store', 'type' => 'storage']);
    $storekeeper = User::factory()->create();
    $storekeeper->assignRole(Role::firstOrCreate(['name' => 'super_admin']));

    Livewire::actingAs($storekeeper)
        ->test(MyStoreCount::class)
        ->call('startCount', 'both');

    expect(CountSession::count())->toBe(0);
});
