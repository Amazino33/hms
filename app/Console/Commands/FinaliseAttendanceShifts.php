<?php

namespace App\Console\Commands;

use App\Services\Attendance\ShiftFinaliser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Judges shifts that are over.
 *
 * Every fifteen minutes rather than nightly, so the board is useful during
 * the day and a device coming back online is picked up promptly. Idempotent,
 * so a double run, a missed run or a redeploy mid-run all come out the same.
 */
class FinaliseAttendanceShifts extends Command
{
    protected $signature = 'attendance:finalise
        {--days=3 : How many days back to look}';

    protected $description = 'Evaluate finished shifts into attendance records and fines';

    public function handle(ShiftFinaliser $finaliser): int
    {
        $counts = $finaliser->run((int) $this->option('days'));

        $summary = sprintf(
            'finalised %d, waiting %d, already recorded %d, review items %d',
            $counts['finalised'],
            $counts['skipped_waiting'],
            $counts['skipped_existing'],
            $counts['review_items'],
        );

        // info() is dropped by production's LOG_LEVEL=warning, so this line is
        // for a human reading the console. Anything genuinely wrong raises.
        Log::info('attendance:finalise — '.$summary);

        $this->info('Attendance finalised: '.$summary);

        if ($counts['review_items'] > 0) {
            $this->warn($counts['review_items'].' item(s) need a look in the review queue.');
        }

        return self::SUCCESS;
    }
}
