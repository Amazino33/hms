<?php

namespace App\Services\Attendance;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Marking somebody outside the attendance system entirely.
 *
 * An exempt user is never resolved into shifts, so they can never be late,
 * absent or fined, and never show in the "no schedule" warning. That is the
 * owner and the CEO — and it is also, in the wrong hands, a way for anyone to
 * quietly step out of the rules. So only a super admin may set it, and every
 * change records who did it and what it was before.
 */
class AttendanceExemptionService
{
    /**
     * @throws ValidationException
     */
    public static function set(User $user, bool $exempt, User $actor): User
    {
        if (! $actor->hasRole('super_admin')) {
            throw ValidationException::withMessages([
                'attendance_exempt' => 'Only a super admin can change attendance exemption.',
            ]);
        }

        $was = (bool) $user->attendance_exempt;

        if ($was === $exempt) {
            return $user;
        }

        // forceFill because attendance_exempt is deliberately not fillable —
        // this service is the only writer.
        $user->forceFill(['attendance_exempt' => $exempt])->save();

        activity('user')
            ->performedOn($user)
            ->causedBy($actor)
            ->withProperties(['old' => ['attendance_exempt' => $was], 'attributes' => ['attendance_exempt' => $exempt]])
            ->log(($exempt ? 'Exempted ' : 'Un-exempted ').$user->name.' from attendance tracking');

        return $user->refresh();
    }

    public static function canChange(?User $actor): bool
    {
        return $actor?->hasRole('super_admin') ?? false;
    }
}
