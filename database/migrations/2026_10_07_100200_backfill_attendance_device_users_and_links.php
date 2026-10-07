<?php

use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carries the existing device knowledge into the new tables.
     *
     * Three sources, in order of authority:
     *   1. biometric_enrollments  — the names, pushed by the device or typed
     *      in by hand. Left in place and untouched; Phase 2 retires it.
     *   2. attendance_logs        — every badge that has ever punched, which
     *      includes IDs nobody has named yet.
     *   3. users.biometric_id     — the current pairing, which becomes the
     *      first link row for each paired person.
     *
     * A link's effective_from is the date of that badge's earliest punch,
     * because that is the earliest date the pairing can be evidenced. Falling
     * back to the user's created_at would otherwise claim a link existed
     * before the device had ever seen the badge.
     */
    public function up(): void
    {
        $this->assertNoDuplicateBiometricIds();

        $this->seedDeviceUsersFromEnrollments();
        $this->seedDeviceUsersFromPunches();
        $this->stampFirstSeen();
        $this->seedLinksFromPairings();
    }

    /**
     * Two users sharing a device ID means the existing pairing is already
     * ambiguous, and every punch from that badge is attributed by a
     * coin-flip. Auto-resolving would pick a winner silently, so this stops
     * and names them instead.
     */
    private function assertNoDuplicateBiometricIds(): void
    {
        $duplicates = DB::table('users')
            ->whereNotNull('biometric_id')
            ->where('biometric_id', '!=', '')
            ->select('biometric_id', DB::raw('count(*) as total'))
            ->groupBy('biometric_id')
            ->having('total', '>', 1)
            ->pluck('total', 'biometric_id');

        if ($duplicates->isEmpty()) {
            return;
        }

        $detail = [];

        foreach ($duplicates as $biometricId => $total) {
            $names = DB::table('users')
                ->where('biometric_id', $biometricId)
                ->pluck('name', 'id')
                ->map(fn ($name, $id) => "#{$id} {$name}")
                ->implode(', ');

            $detail[] = "device ID {$biometricId} is on {$total} users: {$names}";
        }

        throw new RuntimeException(
            "Cannot add a unique index on users.biometric_id until these are resolved by hand:\n  - "
            .implode("\n  - ", $detail)
            ."\nClear biometric_id on whichever user should not hold the badge, then re-run the migration."
        );
    }

    private function seedDeviceUsersFromEnrollments(): void
    {
        if (! Schema::hasTable('biometric_enrollments')) {
            return;
        }

        foreach (DB::table('biometric_enrollments')->orderBy('id')->cursor() as $enrollment) {
            $this->upsertDeviceUser((string) $enrollment->biometric_id, $enrollment->name ?? null);
        }
    }

    private function seedDeviceUsersFromPunches(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            return;
        }

        $badges = DB::table('attendance_logs')
            ->whereNotNull('biometric_id')
            ->where('biometric_id', '!=', '')
            ->distinct()
            ->pluck('biometric_id');

        foreach ($badges as $badge) {
            // Name stays null: a badge that has punched but was never
            // enrolled under a name has no name to give.
            $this->upsertDeviceUser((string) $badge, null);
        }
    }

    private function upsertDeviceUser(string $deviceUserId, ?string $name): void
    {
        $existing = DB::table('attendance_device_users')->where('device_user_id', $deviceUserId)->first();

        if ($existing === null) {
            DB::table('attendance_device_users')->insert([
                'device_user_id' => $deviceUserId,
                'device_name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ($name !== null && $existing->device_name === null) {
            DB::table('attendance_device_users')
                ->where('id', $existing->id)
                ->update(['device_name' => $name, 'updated_at' => now()]);
        }
    }

    private function stampFirstSeen(): void
    {
        if (! Schema::hasTable('attendance_logs')) {
            return;
        }

        $earliest = DB::table('attendance_logs')
            ->whereNotNull('biometric_id')
            ->select('biometric_id', DB::raw('MIN(punch_time) as first_punch'))
            ->groupBy('biometric_id')
            ->pluck('first_punch', 'biometric_id');

        foreach ($earliest as $badge => $firstPunch) {
            DB::table('attendance_device_users')
                ->where('device_user_id', (string) $badge)
                ->update(['first_seen_at' => $firstPunch]);
        }
    }

    private function seedLinksFromPairings(): void
    {
        $paired = DB::table('users')
            ->whereNotNull('biometric_id')
            ->where('biometric_id', '!=', '')
            ->get(['id', 'biometric_id', 'created_at']);

        foreach ($paired as $user) {
            $deviceUser = DB::table('attendance_device_users')
                ->where('device_user_id', (string) $user->biometric_id)
                ->first();

            if ($deviceUser === null) {
                continue;
            }

            $alreadyLinked = DB::table('attendance_device_links')
                ->where('attendance_device_user_id', $deviceUser->id)
                ->exists();

            if ($alreadyLinked) {
                continue;
            }

            DB::table('attendance_device_links')->insert([
                'attendance_device_user_id' => $deviceUser->id,
                'user_id' => $user->id,
                'effective_from' => $this->effectiveFromFor($deviceUser->first_seen_at, $user->created_at),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Punch times are stored UTC; the link is dated in Lagos, because a punch
     * at 00:30 Lagos is 23:30 UTC the previous day and dating the link from
     * the UTC date would start it a day early.
     */
    private function effectiveFromFor(?string $firstSeenAt, ?string $userCreatedAt): string
    {
        $source = $firstSeenAt ?? $userCreatedAt ?? now()->toDateTimeString();

        return CarbonImmutable::parse($source, 'UTC')
            ->setTimezone(VenueTime::TIMEZONE)
            ->toDateString();
    }

    public function down(): void
    {
        DB::table('attendance_device_links')->delete();
        DB::table('attendance_device_users')->delete();
    }
};
