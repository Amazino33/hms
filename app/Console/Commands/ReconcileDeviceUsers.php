<?php

namespace App\Console\Commands;

use App\Services\Attendance\DeviceUserReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The safety net under the BiometricEnrollment observer.
 *
 * An observer only fires for Eloquent writes. A query-builder insert, a raw
 * SQL fix applied on the server, or a future import that bypasses the model
 * would all leave attendance_device_users behind without anyone noticing —
 * and the symptom would be a badge that never appears on the unmatched page,
 * so nobody links it and its punches stay nameless.
 *
 * Idempotent, so it is safe hourly and safe to run by hand at any time.
 *
 * TODO(Phase 2): retire alongside biometric_enrollments.
 */
class ReconcileDeviceUsers extends Command
{
    protected $signature = 'attendance:reconcile-device-users';

    protected $description = 'Bring attendance device users in step with enrolments and punches';

    public function handle(): int
    {
        $counts = DeviceUserReconciler::reconcile();

        $summary = sprintf(
            'created %d, renamed %d, first-seen stamped %d, skipped retired %d',
            $counts['created'],
            $counts['renamed'],
            $counts['first_seen_set'],
            $counts['skipped_retired'],
        );

        // Logged at info, which this server's LOG_LEVEL=warning discards — so
        // the console output is the real report, and anything worth waking
        // somebody for is raised below.
        Log::info('attendance:reconcile-device-users — '.$summary);

        $this->info('Device users reconciled: '.$summary);

        if ($counts['created'] > 0) {
            $this->warn($counts['created'].' badge(s) appeared that the observer had not mirrored.');
            $this->line('That means something wrote biometric_enrollments without going through the model.');
        }

        return self::SUCCESS;
    }
}
