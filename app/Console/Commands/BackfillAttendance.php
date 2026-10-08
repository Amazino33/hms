<?php

namespace App\Console\Commands;

use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\AttendanceLog;
use App\Models\User;
use App\Services\Attendance\ShiftFinaliser;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Judges a stretch of history so the board and the reports have something in
 * them.
 *
 * Separate from attendance:finalise on purpose. The routine run is floored at
 * the engine start date so it can never reach back over months nobody was
 * being tracked for; this asks for an explicit range instead, and says plainly
 * what it is about to create before it does.
 *
 * Everything it writes is shadow. Staff were not told the rules existed on
 * those days, so nothing here can ever be charged.
 */
class BackfillAttendance extends Command
{
    protected $signature = 'attendance:backfill
        {--from= : First shift date (Y-m-d, Lagos)}
        {--to= : Last shift date (Y-m-d, Lagos)}
        {--user= : Limit to one user id}
        {--dry-run : Report what would be created without writing}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Populate attendance records from past punches (always shadow, never charged)';

    public function handle(ShiftFinaliser $finaliser): int
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if (! $from || ! $to) {
            $this->error('Both --from and --to are required.');
            $this->line('Example: php artisan attendance:backfill --from=2026-09-01 --to=2026-10-07 --dry-run');

            return self::FAILURE;
        }

        $start = CarbonImmutable::parse($from, VenueTime::TIMEZONE)->startOfDay();
        $end = CarbonImmutable::parse($to, VenueTime::TIMEZONE)->startOfDay();

        $user = null;

        if ($this->option('user')) {
            $user = User::find($this->option('user'));

            if ($user === null) {
                $this->error('No user with id '.$this->option('user').'.');

                return self::FAILURE;
            }
        }

        if (! $this->checkPrerequisites($start, $end)) {
            return self::FAILURE;
        }

        $this->warnAboutTimestamps($start);

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->option('force') && ! $this->confirmWrite($finaliser, $start, $end, $user)) {
            $this->line('Nothing written.');

            return self::SUCCESS;
        }

        $counts = $finaliser->backfill($start, $end, $user, $dryRun);

        $this->report($counts, $dryRun);

        return self::SUCCESS;
    }

    /**
     * The two things without which this does nothing at all, reported as
     * guidance rather than as an empty result nobody can explain.
     */
    private function checkPrerequisites(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $scheduled = AttendanceShiftAssignment::query()
            ->whereDate('effective_from', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start->toDateString()))
            ->distinct()
            ->count('user_id');

        if ($scheduled === 0) {
            $this->error('Nobody has a shift schedule covering that range, so there is nothing to judge.');
            $this->newLine();
            $this->line('Attendance is measured against the schedule, never against who happened to punch.');
            $this->line('Set up shift templates and put staff on them first:');
            $this->line('  1. Attendance → Shift Templates');
            $this->line('  2. Each staff profile → Schedule → Change schedule');
            $this->line('Backdate the "Starts" date to cover the period you want filled in.');

            return false;
        }

        $punches = AttendanceLog::query()
            ->where('punch_time', '>=', $start->startOfDay()->utc())
            ->where('punch_time', '<', $end->addDay()->startOfDay()->utc())
            ->count();

        $this->info($scheduled.' staff member(s) have a schedule covering this range.');
        $this->line($punches.' punch(es) recorded in it.');

        if ($punches === 0) {
            $this->newLine();
            $this->warn('No punches at all in that range — every shift would be judged absent.');
        }

        return true;
    }

    private function warnAboutTimestamps(CarbonImmutable $start): void
    {
        $boundary = CarbonImmutable::parse(config('attendance.timestamp_fix_at'), 'UTC');

        if ($start->utc()->greaterThanOrEqualTo($boundary)) {
            return;
        }

        $this->newLine();
        $this->warn('This range reaches before '.$boundary->setTimezone(VenueTime::TIMEZONE)->format('j M Y H:i').'.');
        $this->line('Punches before then were stored an hour fast, so arrivals read later than they were');
        $this->line('and lateness in that period will be overstated. The records are still worth having,');
        $this->line('but do not quote their lateness figures to anybody.');
    }

    private function confirmWrite(ShiftFinaliser $finaliser, CarbonImmutable $start, CarbonImmutable $end, ?User $user): bool
    {
        $preview = $finaliser->backfill($start, $end, $user, dryRun: true);

        $this->newLine();
        $this->info('This would create '.$preview['judged'].' attendance record(s):');
        $this->renderOutcomes($preview['by_outcome']);
        $this->newLine();
        $this->line('Shadow charges that would be recorded (never taken from anybody):');
        $this->line('  Fines:        ₦'.number_format($preview['fines']));
        $this->line('  Pay withheld: ₦'.number_format($preview['pay']));

        return $this->confirm('Write these records?', false);
    }

    private function report(array $counts, bool $dryRun): void
    {
        $this->newLine();
        $this->info(($dryRun ? 'Would create ' : 'Created ').$counts['judged'].' attendance record(s).');

        if ($counts['skipped_existing'] > 0) {
            $this->line($counts['skipped_existing'].' already had a record and were left alone.');
        }

        $this->renderOutcomes($counts['by_outcome']);

        $this->newLine();
        $this->line('Shadow fines:        ₦'.number_format($counts['fines']));
        $this->line('Shadow pay withheld: ₦'.number_format($counts['pay']));
        $this->newLine();
        $this->line('All of it is shadow — nothing here is charged to anybody, and switching');
        $this->line('live fines on later will not convert it.');
    }

    private function renderOutcomes(array $byOutcome): void
    {
        if ($byOutcome === []) {
            return;
        }

        ksort($byOutcome);

        $this->table(
            ['Outcome', 'Shifts'],
            collect($byOutcome)->map(fn (int $n, string $o) => [str_replace('_', ' ', ucfirst($o)), $n])->all(),
        );
    }
}
