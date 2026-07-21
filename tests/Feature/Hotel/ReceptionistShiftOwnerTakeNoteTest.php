<?php

use App\Filament\Pages\ReceptionistShift;
use App\Models\OwnerTakeNote;
use App\Models\Shift;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Filament Page components don't mount cleanly through Livewire::test()'s
 * snapshot protocol outside a real panel request (matching the established
 * pattern in tests/Feature/Cashier/SettlementConfirmNotificationTest.php),
 * so the page's action methods are driven directly here instead.
 */
function loadReceptionistShiftPage(User $actingAs): ReceptionistShift
{
    auth()->login($actingAs);

    return new ReceptionistShift();
}

it('records an owner-take note against the receptionist\'s current shift', function () {
    $receptionist = User::factory()->create();
    $receptionist->assignRole(Role::firstOrCreate(['name' => 'receptionist']));
    $shift = Shift::create(['user_id' => $receptionist->id, 'type' => 'receptionist', 'started_at' => now(), 'status' => 'active']);

    $page = loadReceptionistShiftPage($receptionist);
    $page->ownerTakeAmount = 3000;
    $page->ownerTakeDescription = 'Oga took cash from the drawer';
    $page->recordOwnerTake();

    $note = OwnerTakeNote::first();
    expect($note)->not->toBeNull();
    expect($note->shift_id)->toBe($shift->id);
    expect($note->recorded_by)->toBe($receptionist->id);
    expect((float) $note->amount)->toEqual(3000.0);
});

it('refuses to save a receptionist owner-take note with no description', function () {
    $receptionist = User::factory()->create();
    $receptionist->assignRole(Role::firstOrCreate(['name' => 'receptionist']));
    Shift::create(['user_id' => $receptionist->id, 'type' => 'receptionist', 'started_at' => now(), 'status' => 'active']);

    $page = loadReceptionistShiftPage($receptionist);
    $page->ownerTakeDescription = '   ';
    $page->recordOwnerTake();

    expect(OwnerTakeNote::count())->toBe(0);
});

it('does not block declaring end of shift afterward', function () {
    $receptionist = User::factory()->create();
    $receptionist->assignRole(Role::firstOrCreate(['name' => 'receptionist']));
    $shift = Shift::create(['user_id' => $receptionist->id, 'type' => 'receptionist', 'started_at' => now(), 'status' => 'active']);

    $page = loadReceptionistShiftPage($receptionist);
    $page->ownerTakeAmount = 1000;
    $page->ownerTakeDescription = 'Oga took a bottle of wine';
    $page->recordOwnerTake();

    $page->declaredCash = 5000;
    $page->declaredPos = 2000;
    $page->declareEnd();

    expect($shift->fresh()->status)->toBe('awaiting_cashier');
    expect(OwnerTakeNote::where('shift_id', $shift->id)->count())->toBe(1);
});
