<?php

use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\StaffDebt;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\PinAuthService;
use App\Services\StaffDeactivationService;
use App\Services\TrustedDeviceService;
use Carbon\CarbonImmutable;
use Database\Seeders\ShieldSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * Staff 0A §4 — the Deactivate / Reactivate actions and the service behind
 * them. The clock is fixed at 12:00 Lagos on 16 Oct, so business day
 * "2026-10-15" closed at 09:00 this morning (already gone) and
 * "2026-10-16" closes at 09:00 tomorrow (notice period).
 */
beforeEach(function () {
    $this->seed(ShieldSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00', 'Africa/Lagos')->utc());

    $this->super = User::factory()->create(['name' => 'Owner']);
    $this->super->assignRole('super_admin');

    $this->waiter = User::factory()->create(['name' => 'Ada Waiter']);
    $this->waiter->assignRole('waiter');
    (new PinAuthService)->setPin($this->waiter, '7391');
    (new TrustedDeviceService)->trust($this->waiter);
});

function deactivateViaAction(User $actor, User $target, array $data): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::actingAs($actor)
        ->test(EditUser::class, ['record' => $target->getRouteKey()])
        ->callAction('deactivate', data: $data);
}

it('deactivates immediately from the action: fields, PIN, phones, sessions and one log entry', function () {
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert([
        'id' => 'waiter-session', 'user_id' => $this->waiter->id, 'ip_address' => '127.0.0.1',
        'user_agent' => 'test', 'payload' => '', 'last_activity' => now()->timestamp,
    ]);

    deactivateViaAction($this->super, $this->waiter, [
        'last_working_day' => '2026-10-15',
        'reason' => 'resigned',
        'note' => 'Moved to Abuja',
    ])->assertHasNoActionErrors();

    $waiter = $this->waiter->fresh();

    expect($waiter->isDeactivated())->toBeTrue();
    expect($waiter->left_at->equalTo(CarbonImmutable::parse('2026-10-16 09:00', 'Africa/Lagos')))->toBeTrue();
    expect($waiter->left_reason)->toBe('resigned');
    expect($waiter->left_note)->toBe('Moved to Abuja');
    expect($waiter->deactivated_by)->toBe($this->super->id);

    expect($waiter->pin_hash)->toBeNull();
    expect($waiter->pin_lookup_hash)->toBeNull();
    expect(TrustedDevice::where('user_id', $waiter->id)->whereNull('revoked_at')->exists())->toBeFalse();
    expect(DB::table('sessions')->where('user_id', $waiter->id)->exists())->toBeFalse();

    $log = Activity::where('description', 'Staff deactivated')->sole();
    expect($log->causer_id)->toBe($this->super->id);
    expect($log->properties['reason'])->toBe('resigned');
    // No personal values: the free-text note never reaches the log.
    expect(json_encode($log->toArray()))->not->toContain('Abuja');

    // Still in the database, still in their history.
    expect(User::find($waiter->id))->not->toBeNull();
    expect($waiter->hasRole('waiter'))->toBeTrue();
});

it('writes everything or nothing', function () {
    // Make the last step inside the transaction fail.
    config(['session.driver' => 'database', 'session.table' => 'no_such_table']);

    expect(fn () => app(StaffDeactivationService::class)->deactivate($this->waiter, '2026-10-15', 'dismissed', null, $this->super))
        ->toThrow(\Illuminate\Database\QueryException::class);

    $waiter = $this->waiter->fresh();
    expect($waiter->left_at)->toBeNull();
    expect($waiter->left_reason)->toBeNull();
    expect($waiter->pin_hash)->not->toBeNull();
    expect(TrustedDevice::where('user_id', $waiter->id)->whereNull('revoked_at')->exists())->toBeTrue();
    expect(Activity::where('description', 'Staff deactivated')->exists())->toBeFalse();
});

it('keeps a future leaver fully active until their last business day closes, then finalises them', function () {
    app(StaffDeactivationService::class)->deactivate($this->waiter, '2026-10-16', 'contract_ended', null, $this->super);

    $waiter = $this->waiter->fresh();
    expect($waiter->isDeactivated())->toBeFalse();
    expect($waiter->pin_hash)->not->toBeNull();
    expect(User::active()->whereKey($waiter->id)->exists())->toBeTrue();
    expect(Activity::where('description', 'Staff deactivated')->sole()->properties['effective'])->toBe('scheduled');

    // 09:00 Lagos tomorrow is the moment they are gone.
    $this->travelTo(CarbonImmutable::parse('2026-10-17 08:59', 'Africa/Lagos')->utc());
    expect($waiter->fresh()->isDeactivated())->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-10-17 09:00', 'Africa/Lagos')->utc());
    expect($waiter->fresh()->isDeactivated())->toBeTrue();
    expect(User::active()->whereKey($waiter->id)->exists())->toBeFalse();

    $this->artisan('hms:finalise-deactivations')->assertSuccessful();

    $waiter = $waiter->fresh();
    expect($waiter->pin_hash)->toBeNull();
    expect(TrustedDevice::where('user_id', $waiter->id)->whereNull('revoked_at')->exists())->toBeFalse();
});

it('shows outstanding debt in the confirmation without writing to it', function () {
    StaffDebt::create([
        'user_id' => $this->waiter->id, 'amount' => 5000, 'reason' => 'shift_shortfall',
        'status' => 'open', 'created_by' => $this->super->id,
    ]);
    $debtsBefore = StaffDebt::all()->toArray();

    Livewire::actingAs($this->super)
        ->test(EditUser::class, ['record' => $this->waiter->getRouteKey()])
        ->mountAction('deactivate')
        ->assertMountedActionModalSee('₦5,000.00');

    expect(app(StaffDeactivationService::class)->outstandingDebt($this->waiter))->toBe(5000.0);
    expect(StaffDebt::all()->toArray())->toBe($debtsBefore);
});

it('requires a note when the reason is other', function () {
    deactivateViaAction($this->super, $this->waiter, [
        'last_working_day' => '2026-10-15',
        'reason' => 'other',
        'note' => '',
    ])->assertHasActionErrors(['note' => 'required']);

    expect($this->waiter->fresh()->left_at)->toBeNull();
});

it('hides deactivate and reactivate from anyone without deactivate_staff', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    Livewire::actingAs($admin)
        ->test(EditUser::class, ['record' => $this->waiter->getRouteKey()])
        ->assertActionHidden('deactivate')
        ->assertActionHidden('reactivate');

    expect(fn () => app(StaffDeactivationService::class)->deactivate($this->waiter, '2026-10-15', 'resigned', null, $admin))
        ->toThrow(ValidationException::class);
    expect($this->waiter->fresh()->left_at)->toBeNull();
});

it('refuses deactivating yourself or the last active super admin', function () {
    $service = app(StaffDeactivationService::class);

    expect(fn () => $service->deactivate($this->super, '2026-10-15', 'resigned', null, $this->super))
        ->toThrow(ValidationException::class, "You can't deactivate your own account.");

    // While another super admin is active, one may be deactivated.
    $second = User::factory()->create();
    $second->assignRole('super_admin');
    $service->deactivate($second, '2026-10-15', 'resigned', null, $this->super);
    expect($second->fresh()->isDeactivated())->toBeTrue();

    // Now Owner is the last active one. Someone else holding both
    // permissions — the CEO, later — still cannot remove them.
    $ceo = User::factory()->create();
    $ceo->assignRole('ceo');
    $ceo->givePermissionTo(['assign_staff_roles', 'deactivate_staff']);

    expect(fn () => $service->deactivate($this->super, '2026-10-15', 'resigned', null, $ceo))
        ->toThrow(ValidationException::class, 'is the last active super admin');

    expect($this->super->fresh()->left_at)->toBeNull();
});

it('reactivates: clears every leaving field, keeps the PIN empty, logs it', function () {
    $service = app(StaffDeactivationService::class);
    $service->deactivate($this->waiter, '2026-10-15', 'absconded', null, $this->super);

    Livewire::actingAs($this->super)
        ->test(EditUser::class, ['record' => $this->waiter->getRouteKey()])
        ->assertActionVisible('reactivate')
        ->assertActionHidden('deactivate')
        ->callAction('reactivate');

    $waiter = $this->waiter->fresh();
    expect($waiter->left_at)->toBeNull();
    expect($waiter->left_reason)->toBeNull();
    expect($waiter->left_note)->toBeNull();
    expect($waiter->deactivated_by)->toBeNull();
    expect($waiter->isDeactivated())->toBeFalse();
    expect($waiter->pin_hash)->toBeNull();
    expect(Activity::where('description', 'Staff reactivated')->where('causer_id', $this->super->id)->exists())->toBeTrue();
});
