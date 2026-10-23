<?php

use App\Exceptions\StaffDeletionForbiddenException;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Livewire\Livewire;

/**
 * Staff 0A §2. A staff record is never deleted — by the model, the policy
 * or any screen — super admin included.
 */
beforeEach(function () {
    $this->seed(ShieldSeeder::class);
});

it('refuses a model delete with a dedicated exception, and keeps the roles', function () {
    $waiter = User::factory()->create();
    $waiter->assignRole('waiter');

    expect(fn () => $waiter->delete())
        ->toThrow(StaffDeletionForbiddenException::class, 'Staff records cannot be deleted. Deactivate the staff member instead.');

    // The guard runs before Spatie's own deleting listener, which used to
    // strip every role even when the delete then failed.
    expect(User::find($waiter->id))->not->toBeNull();
    expect($waiter->fresh()->hasRole('waiter'))->toBeTrue();
});

it('refuses forceDelete too', function () {
    $user = User::factory()->create();

    // Event-skipping deletes (deleteQuietly, query-builder deletes) cannot be
    // stopped by a listener; NoMassUserDeletionTest forbids them in source.
    expect(fn () => $user->forceDelete())->toThrow(StaffDeletionForbiddenException::class);
    expect(User::find($user->id))->not->toBeNull();
});

it('denies every delete ability in the policy, even to a super admin', function () {
    $super = User::factory()->create();
    $super->assignRole('super_admin');
    $target = User::factory()->create();

    expect($super->can('delete', $target))->toBeFalse();
    expect($super->can('deleteAny', User::class))->toBeFalse();
    expect($super->can('forceDelete', $target))->toBeFalse();
    expect($super->can('forceDeleteAny', User::class))->toBeFalse();
});

it('offers no delete action on the users list or the edit page', function () {
    $super = User::factory()->create();
    $super->assignRole('super_admin');
    $target = User::factory()->create();
    $target->assignRole('waiter');

    Livewire::actingAs($super)
        ->test(ListUsers::class)
        ->assertTableActionDoesNotExist('delete')
        ->assertTableActionDoesNotExist('forceDelete');

    Livewire::actingAs($super)
        ->test(EditUser::class, ['record' => $target->getRouteKey()])
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('forceDelete');
});
