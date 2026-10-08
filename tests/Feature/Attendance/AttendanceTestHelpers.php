<?php

/**
 * Shared fixtures for the attendance engine tests.
 *
 * Kept out of any one test file because Pest only loads the file it is
 * running: helpers declared inside ShiftFinaliserTest exist for that file
 * alone, and a sibling file calling them fails with a bare "undefined
 * function" that says nothing about why.
 *
 * Named without the Test suffix so Pest does not try to run it.
 */

use App\Models\Attendance\AttendanceDeviceLink;
use App\Models\Attendance\AttendanceDeviceUser;
use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\AttendanceLog;
use App\Models\User;
use App\Models\ZktecoDevice;
use App\Services\Attendance\ShiftFinaliser;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;

if (! function_exists('staffed')) {
    /**
     * A staff member with a badge, an active link and a schedule — the shape
     * every ordinary case assumes.
     */
    function staffed(string $deviceId, string $from = '2026-10-01'): User
    {
        $user = User::factory()->create(['biometric_id' => $deviceId]);

        $device = AttendanceDeviceUser::create([
            'device_user_id' => $deviceId,
            'device_name' => $user->name,
        ]);

        AttendanceDeviceLink::create([
            'attendance_device_user_id' => $device->id,
            'user_id' => $user->id,
            'effective_from' => $from,
        ]);

        AttendanceShiftAssignment::create([
            'user_id' => $user->id,
            'attendance_shift_template_id' => test()->template->id,
            'effective_from' => $from,
        ]);

        return $user;
    }
}

if (! function_exists('logPunch')) {
    /**
     * A punch at a Lagos wall-clock time, stored UTC exactly as ingestion
     * does it.
     */
    function logPunch(string $deviceId, string $lagos, ?int $userId = null): AttendanceLog
    {
        return AttendanceLog::create([
            'user_id' => $userId,
            'biometric_id' => $deviceId,
            'punch_time' => CarbonImmutable::parse($lagos, VenueTime::TIMEZONE)->utc(),
        ]);
    }
}

if (! function_exists('deviceHeardAt')) {
    /** Pretend the terminal reported in at this Lagos time. */
    function deviceHeardAt(string $lagos): void
    {
        ZktecoDevice::updateOrCreate(
            ['serial' => 'TESTDEVICE'],
            ['last_push_at' => CarbonImmutable::parse($lagos, VenueTime::TIMEZONE)->utc()],
        );
    }
}

if (! function_exists('finaliseAt')) {
    /**
     * Finalise with a single-day look-back by default, so a test asserting on
     * one date is not also judging the three before it. The real command looks
     * back 3 days; tests that care pass it explicitly.
     */
    function finaliseAt(string $lagos, int $days = 0): array
    {
        return app(ShiftFinaliser::class)->run(
            $days,
            CarbonImmutable::parse($lagos, VenueTime::TIMEZONE),
        );
    }
}
