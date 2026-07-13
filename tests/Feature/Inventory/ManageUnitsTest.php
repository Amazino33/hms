<?php

use App\Filament\Pages\ManageUnits;
use App\Models\PagePermission;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function grantManageUnitsAccess(User $user): void
{
    $user->assignRole(Role::firstOrCreate(['name' => 'manager']));
    PagePermission::firstOrCreate(
        ['page_class' => ManageUnits::class, 'role_name' => 'manager'],
        ['page_class' => ManageUnits::class, 'page_name' => 'Manage Units', 'role_name' => 'manager']
    );
}

it('adds a new unit to the list', function () {
    $manager = User::factory()->create();
    grantManageUnitsAccess($manager);

    Livewire::actingAs($manager)
        ->test(ManageUnits::class)
        ->set('data.name', 'sachet')
        ->call('addUnit');

    expect(Unit::where('name', 'sachet')->exists())->toBeTrue();
});

it('refuses to add a duplicate unit name, case-insensitively', function () {
    $manager = User::factory()->create();
    grantManageUnitsAccess($manager);
    Unit::create(['name' => 'crate']);

    Livewire::actingAs($manager)
        ->test(ManageUnits::class)
        ->set('data.name', 'Crate')
        ->call('addUnit');

    expect(Unit::where('name', 'Crate')->exists())->toBeFalse();
    expect(Unit::count())->toBe(1);
});

it('removes a unit from the list without touching products already using that name', function () {
    $manager = User::factory()->create();
    grantManageUnitsAccess($manager);
    $unit = Unit::create(['name' => 'carton']);

    Livewire::actingAs($manager)
        ->test(ManageUnits::class)
        ->call('deleteUnit', $unit->id);

    expect(Unit::find($unit->id))->toBeNull();
});
