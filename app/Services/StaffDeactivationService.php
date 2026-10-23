<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\StaffDebt;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only way a staff member leaves: never a delete (User::booting()
 * forbids that), always a dated deactivation on the same record, so their
 * shifts, debts, orders and stock history keep their name.
 *
 * users.left_at is the instant they stop working. The form asks for the
 * last working day; that day runs to the close of its business day (09:00
 * Lagos the next morning, BusinessDay), so a bartender on their final night
 * is not cut off at midnight. This is also what ShiftScheduleResolver
 * already assumes: left_at is the first moment they are gone.
 *
 * A future left_at is a notice period — fully active until then. Every
 * enforcement point reads User::isDeactivated(), so they are locked out the
 * moment it passes; `hms:finalise-deactivations` then clears what is left
 * (PIN, trusted phones, sessions) within the hour.
 */
class StaffDeactivationService
{
    /**
     * The left_at instant for a given last working day (Y-m-d, Lagos).
     */
    public static function leftAtFor(string $lastWorkingDay): CarbonImmutable
    {
        return BusinessDay::boundsFor($lastWorkingDay)[1];
    }

    /**
     * @throws ValidationException
     */
    public function deactivate(User $user, string $lastWorkingDay, string $reason, ?string $note, User $actor): User
    {
        $this->assertMayDeactivate($user, $actor);

        if (! array_key_exists($reason, User::LEFT_REASONS)) {
            throw ValidationException::withMessages(['reason' => 'Choose a reason from the list.']);
        }

        $note = filled($note) ? trim($note) : null;

        if ($reason === 'other' && $note === null) {
            throw ValidationException::withMessages(['note' => 'Add a note explaining the reason.']);
        }

        if ($user->left_at !== null) {
            throw ValidationException::withMessages(['user' => "{$user->name} is already deactivated or leaving. Reactivate them first to change the date."]);
        }

        $leftAt = self::leftAtFor($lastWorkingDay);

        return DB::transaction(function () use ($user, $reason, $note, $actor, $leftAt) {
            $user->forceFill([
                'left_at' => $leftAt,
                'left_reason' => $reason,
                'left_note' => $note,
                'deactivated_by' => $actor->id,
            ])->save();

            $effectiveNow = $leftAt->lte(now());

            if ($effectiveNow) {
                $this->cutOff($user);
            }

            // The reason code only: no note text, no personal values.
            activity('user')
                ->performedOn($user)
                ->causedBy($actor)
                ->withProperties([
                    'reason' => $reason,
                    'left_at' => $leftAt->toIso8601String(),
                    'effective' => $effectiveNow ? 'immediately' : 'scheduled',
                ])
                ->log('Staff deactivated');

            return $user->refresh();
        });
    }

    /**
     * @throws ValidationException
     */
    public function reactivate(User $user, User $actor): User
    {
        if (! StaffAccessGuard::canDeactivate($actor)) {
            throw ValidationException::withMessages(['user' => 'Only the super admin can reactivate staff.']);
        }

        StaffAccessGuard::assertCanManage($actor, $user);

        if ($user->left_at === null) {
            throw ValidationException::withMessages(['user' => "{$user->name} is not deactivated."]);
        }

        return DB::transaction(function () use ($user, $actor) {
            $user->forceFill([
                'left_at' => null,
                'left_reason' => null,
                'left_note' => null,
                'deactivated_by' => null,
            ])->save();

            // The PIN was cleared on deactivation and stays empty: they set a
            // new one themselves, through the normal settings flow.
            activity('user')
                ->performedOn($user)
                ->causedBy($actor)
                ->log('Staff reactivated');

            return $user->refresh();
        });
    }

    /**
     * Everything that still lets a person in, removed: kiosk PIN, trusted
     * phones, open sessions on either guard, and any "remember me" cookie.
     * Safe to repeat.
     */
    public function cutOff(User $user): void
    {
        $user->forceFill([
            'pin_hash' => null,
            'pin_lookup_hash' => null,
            'pin_set_at' => null,
            'remember_token' => Str::random(60),
        ])->save();

        TrustedDevice::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        // Only the database driver can be cleared from here. Any other
        // driver is still covered: EnsureAccountIsActive logs the person
        // out on their very next request.
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }

    /**
     * Notice periods that have now run out: clear what is left. Run hourly
     * by the scheduler.
     *
     * @return int how many people were cut off
     */
    public function finaliseDue(): int
    {
        $due = User::deactivated()
            ->where(fn ($q) => $q->whereNotNull('pin_hash')
                ->orWhereExists(fn ($s) => $s->from('trusted_devices')
                    ->whereColumn('trusted_devices.user_id', 'users.id')
                    ->whereNull('trusted_devices.revoked_at')))
            ->get();

        foreach ($due as $user) {
            DB::transaction(fn () => $this->cutOff($user));
        }

        return $due->count();
    }

    /**
     * What the person still owes, for the confirmation screen. Read only.
     */
    public function outstandingDebt(User $user): float
    {
        return (float) StaffDebt::where('user_id', $user->id)
            ->where('status', '!=', 'settled')
            ->get()
            ->sum(fn (StaffDebt $debt) => $debt->remainingBalance());
    }

    /**
     * Any shift still open in their name, for the confirmation screen. It
     * is not closed here: that goes through the shift's own settlement.
     */
    public function openShift(User $user): ?Shift
    {
        return Shift::where('user_id', $user->id)->active()->latest('started_at')->first();
    }

    /**
     * @throws ValidationException
     */
    private function assertMayDeactivate(User $user, User $actor): void
    {
        if (! StaffAccessGuard::canDeactivate($actor)) {
            throw ValidationException::withMessages(['user' => 'Only the super admin can deactivate staff.']);
        }

        StaffAccessGuard::assertCanManage($actor, $user);

        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => "You can't deactivate your own account."]);
        }

        if ($user->hasRole('super_admin')) {
            $otherActiveSuperAdmins = User::role('super_admin')->active()->whereKeyNot($user->id)->count();

            if ($otherActiveSuperAdmins === 0) {
                throw ValidationException::withMessages(['user' => "{$user->name} is the last active super admin — make someone else super admin first."]);
            }
        }
    }
}
