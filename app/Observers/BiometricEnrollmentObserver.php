<?php

namespace App\Observers;

use App\Models\Attendance\AttendanceDeviceUser;
use App\Models\BiometricEnrollment;
use App\Services\Attendance\DeviceUserReconciler;

/**
 * Mirrors the device's own name store into attendance_device_users.
 *
 * biometric_enrollments is written by ZKTecoController when the terminal
 * pushes a USERINFO record, and by hms:set-machine-name. Both are out of
 * bounds for this phase, so rather than rename the table or change those
 * writers, every write is mirrored forward.
 *
 * Strictly one-way, and strictly names: this never creates, ends or voids a
 * link, and never touches a retired device user. Pairing is a human decision.
 *
 * Observes saved() rather than created()/updated() because updateOrCreate on
 * an unchanged row fires saved but not updated, and a device re-pushing the
 * same name must still keep the mirror current.
 *
 * TODO(Phase 2): ZKTecoController writes attendance_device_users directly and
 * biometric_enrollments is retired, at which point this observer and
 * DeviceUserReconciler both go away.
 */
class BiometricEnrollmentObserver
{
    public function saved(BiometricEnrollment $enrollment): void
    {
        DeviceUserReconciler::mirror(
            (string) $enrollment->biometric_id,
            $enrollment->name,
        );
    }

    public function deleted(BiometricEnrollment $enrollment): void
    {
        // Deliberately nothing. A device user that has punched is part of the
        // attendance record; losing the enrolment row is no reason to drop
        // the badge, and it may well still be linked to somebody.
    }

    /**
     * Kept so the class reads as the full picture rather than leaving the
     * reader to wonder whether creation is handled elsewhere.
     */
    public function created(BiometricEnrollment $enrollment): void
    {
        // Covered by saved(), which fires for inserts too.
    }

    public static function deviceUserFor(string $deviceUserId): ?AttendanceDeviceUser
    {
        return AttendanceDeviceUser::where('device_user_id', $deviceUserId)->first();
    }
}
