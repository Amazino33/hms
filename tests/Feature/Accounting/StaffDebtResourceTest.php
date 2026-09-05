<?php

use App\Models\StaffDebt;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Livewire\Livewire;

it('lets a manager record a repayment against an open debt through the resource action', function () {
    $this->seed(ShieldSeeder::class);

    $manager = User::factory()->create();
    $manager->assignRole('manager');

    $waiter = User::factory()->create();
    $debt = StaffDebt::create([
        'user_id' => $waiter->id,
        'amount' => 3000,
        'reason' => 'shift_shortfall',
        'status' => 'open',
        'created_by' => $manager->id,
    ]);

    Livewire::actingAs($manager)
        ->test(\App\Filament\Resources\StaffDebts\Pages\ListStaffDebts::class)
        ->callTableAction('recordRepayment', $debt, data: [
            'amount' => 3000,
            'method' => 'cash',
        ]);

    $debt->refresh();
    expect($debt->status)->toBe('settled');
    expect($debt->repayments()->count())->toBe(1);
});

it('creates a manual debt with reason=manual and stamps the creator', function () {
    $this->seed(ShieldSeeder::class);

    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    Livewire::actingAs($manager)
        ->test(\App\Filament\Resources\StaffDebts\Pages\CreateStaffDebt::class)
        ->fillForm([
            'user_id' => $waiter->id,
            'amount' => 1000,
            'notes' => 'Till was short at open',
        ])
        ->call('create');

    $debt = StaffDebt::firstOrFail();
    expect($debt->reason)->toBe('manual');
    expect($debt->status)->toBe('open');
    expect($debt->created_by)->toBe($manager->id);
});

/**
 * The list page's own filters, exercised through the table rather than
 * just built — 'origin' is not a column, it is a reason-set lookup, so a
 * wrong query closure would only ever surface when the filter is applied.
 */
it('filters the list to debts raised during a handover, and to ones a person recorded', function () {
    $this->seed(ShieldSeeder::class);

    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $staff = User::factory()->create();

    $fromHandover = StaffDebt::create([
        'user_id' => $staff->id, 'amount' => 3000, 'reason' => 'count_session_shortfall',
        'status' => 'open', 'created_by' => $manager->id,
    ]);
    $typedIn = StaffDebt::create([
        'user_id' => $staff->id, 'amount' => 500, 'reason' => 'manual',
        'status' => 'open', 'created_by' => $manager->id,
    ]);

    Livewire::actingAs($manager)
        ->test(\App\Filament\Resources\StaffDebts\Pages\ListStaffDebts::class)
        ->filterTable('origin', 'handover')
        ->assertCanSeeTableRecords([$fromHandover])
        ->assertCanNotSeeTableRecords([$typedIn])
        ->filterTable('origin', 'recorded')
        ->assertCanSeeTableRecords([$typedIn])
        ->assertCanNotSeeTableRecords([$fromHandover]);
});

it('filters the list to several staff at once and to a date range', function () {
    $this->seed(ShieldSeeder::class);

    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $chidi = User::factory()->create(['name' => 'Chidi']);
    $ngozi = User::factory()->create(['name' => 'Ngozi']);
    $emeka = User::factory()->create(['name' => 'Emeka']);

    $debts = collect([$chidi, $ngozi, $emeka])->map(fn (User $staff) => StaffDebt::create([
        'user_id' => $staff->id, 'amount' => 1000, 'reason' => 'manual',
        'status' => 'open', 'created_by' => $manager->id,
    ]));

    $old = StaffDebt::create([
        'user_id' => $chidi->id, 'amount' => 250, 'reason' => 'manual',
        'status' => 'open', 'created_by' => $manager->id,
    ]);
    $old->forceFill(['created_at' => now()->subYear()])->save();

    Livewire::actingAs($manager)
        ->test(\App\Filament\Resources\StaffDebts\Pages\ListStaffDebts::class)
        ->filterTable('user', [$chidi->id, $ngozi->id])
        ->assertCanSeeTableRecords([$debts[0], $debts[1]])
        ->assertCanNotSeeTableRecords([$debts[2]])
        ->filterTable('raised_between', ['from' => now()->subDay()->toDateString()])
        ->assertCanNotSeeTableRecords([$old]);
});
