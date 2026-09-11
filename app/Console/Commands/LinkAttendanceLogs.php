<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Re-links attendance logs to staff after a badge is paired.
 *
 * ZKTecoController resolves the user when the punch arrives, which means a
 * punch pushed before anyone typed that machine ID into a staff profile is
 * stored with user_id = NULL for good — the terminal buffers offline and
 * dumps its backlog the moment it connects, so the very first sync is
 * guaranteed to land before any pairing exists. That is exactly the "Machine
 * ID filled in, Staff Member blank" row in the admin table.
 *
 * Pairing is retroactive here and nowhere else: this only fills in rows that
 * were never matched, and never re-points a row that already names someone,
 * so a badge later reassigned to a different person leaves the old history
 * attributed to whoever actually punched it.
 *
 * It deliberately does NOT apply late-arrival penalties for the rows it
 * links. Back-dating fines onto staff who had no idea they were being
 * tracked yet is a decision for management, not a side effect of a
 * maintenance command.
 */
class LinkAttendanceLogs extends Command
{
    protected $signature = 'hms:link-attendance-logs
        {--dry-run : Report what would be linked without writing anything}';

    protected $description = 'Attach orphaned attendance logs to the staff member whose biometric ID matches';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $orphans = AttendanceLog::whereNull('user_id')
            ->whereNotNull('biometric_id')
            ->selectRaw('biometric_id, count(*) as total')
            ->groupBy('biometric_id')
            ->pluck('total', 'biometric_id');

        if ($orphans->isEmpty()) {
            $this->info('No unlinked attendance logs. Nothing to do.');

            return self::SUCCESS;
        }

        $staff = User::whereNotNull('biometric_id')
            ->pluck('name', 'biometric_id');

        $rows = [];
        $linkable = 0;
        $unmatched = 0;

        foreach ($orphans as $biometricId => $total) {
            $name = $staff[$biometricId] ?? null;

            $rows[] = [$biometricId, $total, $name ?? '— not paired to anyone —'];

            if ($name !== null) {
                $linkable += $total;
            } else {
                $unmatched += $total;
            }
        }

        $this->table(['Machine ID', 'Unlinked punches', 'Staff member'], $rows);

        if ($linkable === 0) {
            $this->warn("None of these machine IDs are paired to a staff profile yet — {$unmatched} punch(es) left unlinked.");
            $this->line('Set the "Biometric Machine ID" field on the relevant user, then run this again.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Dry run: {$linkable} punch(es) would be linked. Re-run without --dry-run to apply.");

            return self::SUCCESS;
        }

        $linked = 0;

        foreach ($staff as $biometricId => $name) {
            $userId = User::where('biometric_id', $biometricId)->value('id');

            $linked += AttendanceLog::whereNull('user_id')
                ->where('biometric_id', $biometricId)
                ->update(['user_id' => $userId]);
        }

        $this->info("Linked {$linked} attendance log(s) to staff.");

        if ($unmatched > 0) {
            $this->warn("{$unmatched} punch(es) remain unlinked — their machine IDs are not on any staff profile.");
        }

        return self::SUCCESS;
    }
}
