<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\User;

/**
 * Attaches already-stored punches to the staff member who owns that badge.
 *
 * ZKTecoController resolves the user when a punch arrives, which is right for
 * live traffic but leaves a permanent gap for anything that arrived first: the
 * terminal buffers punches while offline and dumps the whole backlog the
 * moment it connects, so the opening sync is guaranteed to land before anyone
 * has typed that machine ID into a staff profile. Those rows keep
 * user_id = NULL and show a blank Staff Member in the admin table.
 *
 * Both entry points — the UserObserver (automatic, the moment a Biometric
 * Machine ID is saved) and hms:link-attendance-logs (manual sweep) — come
 * through here so the matching rule can never drift between them.
 */
class AttendanceLinker
{
    /**
     * Claim every unclaimed punch bearing this user's badge.
     *
     * Only never-matched rows are touched. A badge reassigned to a new
     * starter therefore leaves last month's history attributed to whoever
     * actually punched it, rather than silently rewriting the payroll record
     * of someone who has already been paid against it.
     *
     * @return int the number of logs linked
     */
    public static function linkFor(User $user): int
    {
        if (blank($user->biometric_id)) {
            return 0;
        }

        return AttendanceLog::whereNull('user_id')
            ->where('biometric_id', $user->biometric_id)
            ->update(['user_id' => $user->id]);
    }
}
