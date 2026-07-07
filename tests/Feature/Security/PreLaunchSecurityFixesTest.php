<?php

use App\Filament\Pages\DailyReport;
use App\Filament\Pages\TableDetail;
use App\Models\PagePermission;
use App\Models\User;
use Filament\Panel;
use Spatie\Permission\Models\Role;

it('denies panel access cleanly (false, not a TypeError) for a user with zero roles', function () {
    $user = User::factory()->create();
    expect($user->roles()->count())->toBe(0);

    $panel = \Filament\Facades\Filament::getPanel('admin');

    expect($user->canAccessPanel($panel))->toBeFalse();
});

it('grants panel access to a user with at least one role', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    $panel = \Filament\Facades\Filament::getPanel('admin');

    expect($user->canAccessPanel($panel))->toBeTrue();
});

it('blocks a plain waiter from the End of Day Record page by default', function () {
    $waiter = User::factory()->create();
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    $this->actingAs($waiter);

    expect(DailyReport::canAccess())->toBeFalse();
});

it('allows a manager to reach the End of Day Record page', function () {
    PagePermission::firstOrCreate(
        ['page_class' => DailyReport::class, 'role_name' => 'manager'],
        ['page_class' => DailyReport::class, 'page_name' => 'End of Day Record', 'role_name' => 'manager']
    );

    $manager = User::factory()->create();
    $manager->assignRole(Role::firstOrCreate(['name' => 'manager']));

    $this->actingAs($manager);

    expect(DailyReport::canAccess())->toBeTrue();
});

it('blocks an unrelated role from Table Detail while allowing a waiter through', function () {
    PagePermission::firstOrCreate(
        ['page_class' => TableDetail::class, 'role_name' => 'waiter'],
        ['page_class' => TableDetail::class, 'page_name' => 'Table Details', 'role_name' => 'waiter']
    );

    $chef = User::factory()->create();
    $chef->assignRole(Role::firstOrCreate(['name' => 'chef']));
    $waiter = User::factory()->create();
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    $this->actingAs($chef);
    expect(TableDetail::canAccess())->toBeFalse();

    $this->actingAs($waiter);
    expect(TableDetail::canAccess())->toBeTrue();
});
