<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Attendance\ShiftReevaluationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Re-judges a stretch of shifts after something behind them changed.
 *
 * A reason is required and is written onto every superseded record and voided
 * fine, because "why did my March fine disappear" has to have an answer that
 * is not "somebody ran a command".
 */
class ReevaluateAttendance extends Command
{
    protected $signature = 'attendance:reevaluate
        {--from= : First shift date (Y-m-d, Lagos)}
        {--to= : Last shift date (Y-m-d, Lagos)}
        {--user= : Limit to one user id}
        {--reason= : Why this is being re-evaluated}';

    protected $description = 'Re-evaluate attendance shift records over a date range';

    public function handle(ShiftReevaluationService $service): int
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $reason = trim((string) $this->option('reason'));

        if (! $from || ! $to) {
            $this->error('Both --from and --to are required.');

            return self::FAILURE;
        }

        if ($reason === '') {
            $this->error('--reason is required. It is recorded on every record this changes.');

            return self::FAILURE;
        }

        $user = null;

        if ($this->option('user')) {
            $user = User::find($this->option('user'));

            if ($user === null) {
                $this->error('No user with id '.$this->option('user').'.');

                return self::FAILURE;
            }
        }

        $counts = $service->reevaluate(
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
            $reason,
            $user,
        );

        $this->info(sprintf(
            'Examined %d, changed %d, unchanged %d.',
            $counts['examined'],
            $counts['changed'],
            $counts['unchanged'],
        ));

        if ($counts['skipped_paid'] !== []) {
            $this->newLine();
            $this->warn(count($counts['skipped_paid']).' record(s) were skipped because their fines are already in a payroll run:');
            $this->line('  record ids: '.implode(', ', $counts['skipped_paid']));
            $this->line('  Those have to be corrected through payroll, not here.');
        }

        return self::SUCCESS;
    }
}
