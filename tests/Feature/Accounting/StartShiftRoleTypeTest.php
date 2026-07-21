<?php

use App\Models\Shift;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * The generic "Start Shift" control (topbar ShiftManager) never asks which
 * role someone is clocking in as — User::startShift() must infer it from
 * their actual role for the roles that still use this generic path, or
 * every shift silently defaults to 'waiter' regardless of who's on duty.
 * This was a real production incident: a bartender's own shift never
 * satisfied OrderSplitter's bar-order guard (which checks for a
 * bartender-TYPED shift specifically, not just any active shift), so bar
 * orders kept being rejected as "no bartender on duty" even with the actual
 * bartender clocked in.
 *
 * Bartender/chef themselves no longer start through this generic path at
 * all (see ShiftManagerBartenderChefStartGuardTest) — closing that path was
 * a later, deliberate change once it became clear it let two bartenders
 * show active at once with nothing to reconcile between them, entirely
 * bypassing the handover system. The type-inference concern this file
 * documents still applies to every role that does keep using this control.
 */
it('starts a waiter-typed shift for a plain waiter or any user with no operational role', function () {
    $waiter = User::factory()->create();
    $waiter->assignRole(Role::firstOrCreate(['name' => 'waiter']));

    $noRoleUser = User::factory()->create();

    expect($waiter->startShift()->type)->toBe('waiter');
    expect($noRoleUser->startShift()->type)->toBe('waiter');
});

it('lets a bartender-typed active shift (started through the proper handover/opening-count channel) satisfy the OrderSplitter bar-order guard', function () {
    $bartender = User::factory()->create();
    $bartender->assignRole(Role::firstOrCreate(['name' => 'bartender']));

    Shift::create(['user_id' => $bartender->id, 'type' => 'bartender', 'started_at' => now(), 'status' => 'active']);

    expect(Shift::ofType('bartender')->activeNonStale('bartender')->exists())->toBeTrue();
});
