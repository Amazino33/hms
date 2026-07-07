<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * The generic "Start Shift" control (topbar ShiftManager) never asks which
 * role someone is clocking in as — User::startShift() must infer it from
 * their actual role, or every shift silently defaults to 'waiter' regardless
 * of who's on duty. This is a real production incident: a bartender's own
 * shift never satisfied OrderSplitter's bar-order guard (which checks for a
 * bartender-TYPED shift specifically, not just any active shift), so bar
 * orders kept being rejected as "no bartender on duty" even with the actual
 * bartender clocked in.
 */
it('starts a bartender-typed shift for a user with the bartender role', function () {
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    $shift = $bartender->startShift();

    expect($shift->type)->toBe('bartender');
});

it('starts a chef-typed shift for a user with the chef role', function () {
    $chef = User::factory()->create();
    $chef->assignRole(Role::firstOrCreate(['name' => 'chef']));

    $shift = $chef->startShift();

    expect($shift->type)->toBe('chef');
});

it('starts a waiter-typed shift for a plain waiter or any user with no operational role', function () {
    $waiter = User::factory()->create();
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    $noRoleUser = User::factory()->create();

    expect($waiter->startShift()->type)->toBe('waiter');
    expect($noRoleUser->startShift()->type)->toBe('waiter');
});

it('lets a bartender starting a new shift satisfy the OrderSplitter bar-order guard', function () {
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));
    $bartender->startShift();

    expect(\App\Models\Shift::ofType('bartender')->activeNonStale('bartender')->exists())->toBeTrue();
});
