<?php

use App\Livewire\ShiftManager;
use App\Models\OwnerTakeNote;
use App\Models\Shift;
use App\Models\User;
use Livewire\Livewire;

it('records an owner-take note against the current shift', function () {
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($waiter)
        ->test(ShiftManager::class)
        ->call('load')
        ->call('recordOwnerTake', 5000, 'Oga took 2 crates of beer');

    $note = OwnerTakeNote::first();
    expect($note)->not->toBeNull();
    expect($note->shift_id)->toBe($shift->id);
    expect($note->recorded_by)->toBe($waiter->id);
    expect((float) $note->amount)->toEqual(5000.0);
    expect($note->description)->toBe('Oga took 2 crates of beer');
});

it('works the same way for a receptionist shift', function () {
    $receptionist = User::factory()->create();
    $shift = Shift::create(['user_id' => $receptionist->id, 'type' => 'receptionist', 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($receptionist)
        ->test(ShiftManager::class)
        ->call('load')
        ->call('recordOwnerTake', null, 'Oga took cash from the drawer');

    $note = OwnerTakeNote::first();
    expect($note)->not->toBeNull();
    expect($note->shift_id)->toBe($shift->id);
    expect($note->amount)->toBeNull();
});

it('refuses to save a note with no description', function () {
    $waiter = User::factory()->create();
    Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($waiter)
        ->test(ShiftManager::class)
        ->call('load')
        ->call('recordOwnerTake', 1000, '   ');

    expect(OwnerTakeNote::count())->toBe(0);
});

it('does not block or change ending the shift afterward', function () {
    $waiter = User::factory()->create();
    $shift = Shift::create(['user_id' => $waiter->id, 'type' => 'waiter', 'started_at' => now(), 'status' => 'active']);

    Livewire::actingAs($waiter)
        ->test(ShiftManager::class)
        ->call('load')
        ->call('recordOwnerTake', 2000, 'Oga took drinks for a party')
        ->call('confirmShiftEnd', 500, 0);

    expect($shift->fresh()->ended_at)->not->toBeNull();
    expect(OwnerTakeNote::where('shift_id', $shift->id)->count())->toBe(1);
});

it('does nothing when there is no active shift to attach a note to', function () {
    $waiter = User::factory()->create();

    Livewire::actingAs($waiter)
        ->test(ShiftManager::class)
        ->call('load')
        ->call('recordOwnerTake', 1000, 'Should not save');

    expect(OwnerTakeNote::count())->toBe(0);
});
