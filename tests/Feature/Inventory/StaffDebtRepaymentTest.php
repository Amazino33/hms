<?php

use App\Filament\Resources\StaffDebts\Pages\ListStaffDebts;
use App\Models\StaffDebt;
use App\Models\StaffDebtRepayment;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * StaffDebtPolicy checks real Shield permissions (authUser->can(...)), which
 * ShieldSeeder normally grants — but RefreshDatabase doesn't run seeders, so
 * a fresh test manager needs these granted directly to reach the resource.
 */
function grantStaffDebtManagement(User $manager): void
{
    $role = Role::firstOrCreate(['name' => 'manager']);
    $manager->assignRole($role);

    foreach (['ViewAny:StaffDebt', 'View:StaffDebt', 'Update:StaffDebt'] as $name) {
        $role->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }
}

it('records a partial repayment and reduces the outstanding balance', function () {
    $bartender = User::factory()->create();
    $manager = User::factory()->create();
    grantStaffDebtManagement($manager);

    $debt = StaffDebt::create([
        'user_id' => $bartender->id,
        'amount' => 2000,
        'reason' => 'count_session_shortfall',
        'status' => 'open',
        'created_by' => $manager->id,
    ]);

    Livewire::actingAs($manager)
        ->test(ListStaffDebts::class)
        ->callTableAction('recordRepayment', $debt, data: [
            'amount' => 500,
            'method' => 'cash',
            'notes' => 'Partial cash payment',
        ]);

    $debt->refresh();
    expect($debt->totalRepaid())->toBe(500.0);
    expect($debt->remainingBalance())->toBe(1500.0);
    expect($debt->status)->toBe('partially_settled');

    $repayment = StaffDebtRepayment::first();
    expect($repayment->recorded_by)->toBe($manager->id);
});

it('settles a debt once fully repaid', function () {
    $bartender = User::factory()->create();
    $manager = User::factory()->create();

    $debt = StaffDebt::create([
        'user_id' => $bartender->id,
        'amount' => 1000,
        'reason' => 'count_session_shortfall',
        'status' => 'open',
        'created_by' => $manager->id,
    ]);

    $debt->repayments()->create(['amount' => 1000, 'method' => 'cash', 'recorded_by' => $manager->id]);
    $debt->refreshStatus();

    expect($debt->fresh()->status)->toBe('settled');
    expect($debt->fresh()->remainingBalance())->toBe(0.0);
});

it('rejects a repayment larger than the outstanding balance', function () {
    $bartender = User::factory()->create();
    $manager = User::factory()->create();
    grantStaffDebtManagement($manager);

    $debt = StaffDebt::create([
        'user_id' => $bartender->id,
        'amount' => 1000,
        'reason' => 'count_session_shortfall',
        'status' => 'open',
        'created_by' => $manager->id,
    ]);

    Livewire::actingAs($manager)
        ->test(ListStaffDebts::class)
        ->callTableAction('recordRepayment', $debt, data: [
            'amount' => 5000, // exceeds the 1000 outstanding
            'method' => 'cash',
        ])
        ->assertHasTableActionErrors(['amount' => ['max']]);

    expect(StaffDebtRepayment::count())->toBe(0);
});
