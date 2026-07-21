<?php

use App\Filament\Pages\SettlementDetail;
use App\Models\OwnerTakeNote;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

function loadOwnerTakeSettlementPage(User $actingAs, int $shiftId): SettlementDetail
{
    auth()->login($actingAs);

    $page = new SettlementDetail;
    $page->mount(Request::create('/admin/settlement', 'GET', ['shift' => $shiftId]));

    return $page;
}

it('shows an owner-take note to the cashier while settling that shift', function () {
    $waiter = User::factory()->create();
    $cashier = User::factory()->create();
    $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier']));

    $shift = Shift::create([
        'user_id' => $waiter->id, 'type' => 'waiter',
        'started_at' => now()->subHours(2), 'ended_at' => now(), 'status' => 'awaiting_cashier',
    ]);

    OwnerTakeNote::create([
        'shift_id' => $shift->id,
        'recorded_by' => $waiter->id,
        'amount' => 5000,
        'description' => 'Oga took 2 crates of beer',
    ]);

    $page = loadOwnerTakeSettlementPage($cashier, $shift->id);

    $notes = $page->ownerTakeNotes();
    expect($notes)->toHaveCount(1);
    expect($notes->first()->description)->toBe('Oga took 2 crates of beer');
});

it('returns no notes for a shift that has none', function () {
    $waiter = User::factory()->create();
    $cashier = User::factory()->create();
    $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier']));

    $shift = Shift::create([
        'user_id' => $waiter->id, 'type' => 'waiter',
        'started_at' => now()->subHours(2), 'ended_at' => now(), 'status' => 'awaiting_cashier',
    ]);

    $page = loadOwnerTakeSettlementPage($cashier, $shift->id);

    expect($page->ownerTakeNotes())->toHaveCount(0);
});

it('does not show a note recorded against a different shift', function () {
    $waiter = User::factory()->create();
    $cashier = User::factory()->create();
    $cashier->assignRole(Role::firstOrCreate(['name' => 'cashier']));

    $thisShift = Shift::create([
        'user_id' => $waiter->id, 'type' => 'waiter',
        'started_at' => now()->subHours(2), 'ended_at' => now(), 'status' => 'awaiting_cashier',
    ]);
    $otherShift = Shift::create([
        'user_id' => $waiter->id, 'type' => 'waiter',
        'started_at' => now()->subDays(1), 'ended_at' => now()->subDays(1), 'status' => 'confirmed',
    ]);

    OwnerTakeNote::create(['shift_id' => $otherShift->id, 'recorded_by' => $waiter->id, 'description' => 'Unrelated note']);

    $page = loadOwnerTakeSettlementPage($cashier, $thisShift->id);

    expect($page->ownerTakeNotes())->toHaveCount(0);
});
