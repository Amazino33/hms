<?php

use App\Filament\Pages\CountSessionDetail;
use App\Models\Shift;
use App\Models\User;
use App\Models\WareHouse;
use App\Services\CountSessionService;
use Livewire\Livewire;

it('lets a bartender start their first shift of the day from their own reviewed opening count', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $bartender = User::factory()->create();
    $bartender->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));
    $manager = User::factory()->create();
    $manager->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $countService = new CountSessionService();
    $session = $countService->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);
    $countService->confirmIncoming($session, $bartender->id);
    $session = $countService->submitForReview($session->fresh());
    $session = $countService->finalizeReview($session->fresh(), $manager->id);

    Livewire::actingAs($bartender)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->assertSee('Start My Shift')
        ->call('startMyShift');

    $shift = Shift::where('user_id', $bartender->id)->where('type', 'bartender')->first();
    expect($shift)->not->toBeNull();
    expect($shift->isActive())->toBeTrue();
    expect($shift->opening_count_session_id)->toBe($session->id);
});

it('does not offer the start-shift button to someone else viewing the same reviewed session', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $bartender = User::factory()->create();
    $bartender->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));
    $stranger = User::factory()->create();
    $stranger->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));
    $manager = User::factory()->create();
    $manager->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $countService = new CountSessionService();
    $session = $countService->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);
    $countService->confirmIncoming($session, $bartender->id);
    $session = $countService->submitForReview($session->fresh());
    $session = $countService->finalizeReview($session->fresh(), $manager->id);

    Livewire::actingAs($stranger)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->assertDontSee('Start My Shift');
});

it('does not offer the start-shift button once a shift has already been started from this session', function () {
    $bar = WareHouse::create(['name' => 'Bar', 'type' => 'consumer']);
    $bartender = User::factory()->create();
    $bartender->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));
    $manager = User::factory()->create();
    $manager->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin']));

    $countService = new CountSessionService();
    $session = $countService->openSession('bar_handover', $bar->id, $bartender->id, null, $bartender->id);
    $countService->confirmIncoming($session, $bartender->id);
    $session = $countService->submitForReview($session->fresh());
    $session = $countService->finalizeReview($session->fresh(), $manager->id);

    Shift::create([
        'user_id' => $bartender->id, 'type' => 'bartender', 'opening_count_session_id' => $session->id,
        'started_at' => now(), 'status' => 'active',
    ]);

    Livewire::actingAs($bartender)
        ->test(CountSessionDetail::class, ['session_id' => $session->id])
        ->assertDontSee('Start My Shift');
});
