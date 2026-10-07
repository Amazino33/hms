<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceDeviceUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps attendance_device_users in step with what the terminal knows.
 *
 * The observer on BiometricEnrollment covers the live path, but an observer
 * only fires for Eloquent writes. This is the safety net that does not care
 * how a row got there: it reads biometric_enrollments and attendance_logs
 * directly and brings the device-user table up to date, so a query-builder
 * insert, a raw SQL fix on the server, or an import that bypassed the model
 * all still land.
 *
 * Idempotent by construction — running it twice changes nothing, which is
 * what makes it safe on an hourly schedule.
 *
 * TODO(Phase 2): retire alongside biometric_enrollments once ZKTecoController
 * writes attendance_device_users directly.
 */
class DeviceUserReconciler
{
    /**
     * Bring one badge up to date.
     *
     * A retired badge is never modified: retirement is a decision that the ID
     * is finished with, and the device happily keeps pushing records for IDs
     * it has reassigned.
     */
    public static function mirror(string $deviceUserId, ?string $deviceName = null): ?AttendanceDeviceUser
    {
        if ($deviceUserId === '') {
            return null;
        }

        $deviceUser = AttendanceDeviceUser::where('device_user_id', $deviceUserId)->first();

        if ($deviceUser === null) {
            return AttendanceDeviceUser::create([
                'device_user_id' => $deviceUserId,
                'device_name' => $deviceName,
            ]);
        }

        if ($deviceUser->isRetired()) {
            return $deviceUser;
        }

        // A blank push is the device saying "enrolled, unnamed" — it must not
        // wipe a name already held, whether pushed earlier or typed by hand.
        if (filled($deviceName) && $deviceUser->device_name !== $deviceName) {
            $deviceUser->forceFill(['device_name' => $deviceName])->save();
        }

        return $deviceUser;
    }

    /**
     * Full sweep.
     *
     * @return array{created: int, renamed: int, first_seen_set: int, skipped_retired: int}
     */
    public static function reconcile(): array
    {
        $counts = ['created' => 0, 'renamed' => 0, 'first_seen_set' => 0, 'skipped_retired' => 0];

        $existing = AttendanceDeviceUser::all()->keyBy('device_user_id');

        foreach (self::knownBadges() as $deviceUserId => $deviceName) {
            $deviceUser = $existing->get((string) $deviceUserId);

            if ($deviceUser === null) {
                AttendanceDeviceUser::create([
                    'device_user_id' => (string) $deviceUserId,
                    'device_name' => $deviceName,
                ]);
                $counts['created']++;

                continue;
            }

            if ($deviceUser->isRetired()) {
                $counts['skipped_retired']++;

                continue;
            }

            if (filled($deviceName) && $deviceUser->device_name !== $deviceName) {
                $deviceUser->forceFill(['device_name' => $deviceName])->save();
                $counts['renamed']++;
            }
        }

        $counts['first_seen_set'] = self::stampFirstSeen();

        return $counts;
    }

    /**
     * Every badge the system has heard of: named ones from the enrolment
     * table, plus any that have punched without ever being named.
     *
     * @return array<string, ?string>
     */
    private static function knownBadges(): array
    {
        $badges = [];

        if (Schema::hasTable('attendance_logs')) {
            $punched = DB::table('attendance_logs')
                ->whereNotNull('biometric_id')
                ->where('biometric_id', '!=', '')
                ->distinct()
                ->pluck('biometric_id');

            foreach ($punched as $badge) {
                $badges[(string) $badge] = null;
            }
        }

        if (Schema::hasTable('biometric_enrollments')) {
            // Second so a real name always wins over the null placed above.
            foreach (DB::table('biometric_enrollments')->get(['biometric_id', 'name']) as $row) {
                $badges[(string) $row->biometric_id] = $row->name;
            }
        }

        return $badges;
    }

    /**
     * first_seen_at is the earliest punch, and only ever set once: it is a
     * historical fact, and recomputing it would let a later correction to
     * attendance_logs move a date that other records already cite.
     */
    private static function stampFirstSeen(): int
    {
        if (! Schema::hasTable('attendance_logs')) {
            return 0;
        }

        $earliest = DB::table('attendance_logs')
            ->whereNotNull('biometric_id')
            ->select('biometric_id', DB::raw('MIN(punch_time) as first_punch'))
            ->groupBy('biometric_id')
            ->pluck('first_punch', 'biometric_id');

        $updated = 0;

        foreach ($earliest as $badge => $firstPunch) {
            $updated += AttendanceDeviceUser::where('device_user_id', (string) $badge)
                ->whereNull('first_seen_at')
                ->update(['first_seen_at' => $firstPunch]);
        }

        return $updated;
    }
}
