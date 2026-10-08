<?php

namespace App\Console\Commands;

use App\Models\Attendance\AttendanceShiftTemplate;
use App\Models\User;
use App\Services\Attendance\ShiftAssignmentService;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Puts a lot of people on the same weekly pattern at once.
 *
 * Scheduling thirty staff one profile at a time is the kind of job that does
 * not get finished, and an unfinished rota means the people left off it are
 * silently never checked. This covers the majority who all work the same
 * ordinary week; the exceptions still get done by hand.
 *
 * Rotations are deliberately refused. Every person on a rotation needs their
 * own anchor date — give them all the same one and they work the same days,
 * which on a handover rota means everybody on at once and nobody on the days
 * between.
 *
 * Goes through ShiftAssignmentService, so the overlap rules and the
 * close-then-open behaviour are identical to the UI.
 */
class AssignAttendanceSchedule extends Command
{
    protected $signature = 'attendance:assign-schedule
        {--template= : Shift template id or name}
        {--from= : Date the schedule starts (Y-m-d, Lagos)}
        {--role=* : Limit to staff holding these roles}
        {--user=* : Limit to these user ids}
        {--all : Every trackable staff member}
        {--days= : Working days as ISO numbers, e.g. 1,2,3,4,5,6 for Mon-Sat}
        {--replace : Re-assign people who already have a schedule}
        {--dry-run : List who would be assigned without writing}';

    protected $description = 'Put many staff on the same weekly shift pattern at once';

    public function handle(ShiftAssignmentService $assignments): int
    {
        $template = $this->resolveTemplate();

        if ($template === null) {
            return self::FAILURE;
        }

        if ($template->isRotation()) {
            $this->error('"'.$template->name.'" is a rotation, which cannot be assigned in bulk.');
            $this->newLine();
            $this->line('Everybody on a rotation needs their own anchor date. Give them all the same');
            $this->line('one and they work the same days — on a handover rota that means everybody');
            $this->line('on at once, and nobody on the days in between.');
            $this->line('Assign these from each profile: Schedule → Change schedule, then check the');
            $this->line('"Next 7 shifts" preview before saving.');

            return self::FAILURE;
        }

        $from = CarbonImmutable::parse(
            $this->option('from') ?: CarbonImmutable::now(VenueTime::TIMEZONE)->toDateString(),
            VenueTime::TIMEZONE,
        )->startOfDay();

        $staff = $this->targets();

        if ($staff->isEmpty()) {
            $this->warn('No staff matched. Use --all, --role=waiter, or --user=12 --user=15.');

            return self::SUCCESS;
        }

        $days = $this->workingDays();

        if ($days === false) {
            return self::FAILURE;
        }

        $already = $staff->filter(fn (User $u) => $assignments->currentFor($u, $from) !== null);

        // --replace exists because the usual mistake is assigning the right
        // template with the wrong days: the seeded Day shift covers all seven,
        // so without it every rest day reads as an absence. Re-assigning
        // closes the old row and opens a new one, keeping both in history.
        $toAssign = $this->option('replace')
            ? $staff
            : $staff->reject(fn (User $u) => $already->contains('id', $u->id))->values();

        $this->line('Template: '.$template->name.' ('.$template->describeHours().')');
        $this->line('Starting: '.$from->format('j M Y'));
        $this->line('Days:     '.$this->describeDays($days ?: $template->weekly_days ?? []));
        $this->newLine();

        if ($already->isNotEmpty() && ! $this->option('replace')) {
            $this->line($already->count().' already have a schedule covering that date and are left alone:');
            $this->line('  '.$already->pluck('name')->implode(', '));
            $this->newLine();
        }

        if ($toAssign->isEmpty()) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        $this->info($toAssign->count().' would be put on this pattern:');
        $this->line('  '.$toAssign->pluck('name')->implode(', '));

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line('Dry run — nothing written.');

            return self::SUCCESS;
        }

        $this->newLine();

        if (! $this->confirm('Assign these '.$toAssign->count().' staff?', false)) {
            $this->line('Nothing written.');

            return self::SUCCESS;
        }

        $done = 0;
        $failed = [];

        foreach ($toAssign as $user) {
            try {
                $assignments->assign(
                    $user,
                    $template,
                    $from,
                    weeklyDaysOverride: $days ?: null,
                    reason: 'Bulk assignment',
                    actor: null,
                );
                $done++;
            } catch (ValidationException $e) {
                $failed[] = $user->name.': '.collect($e->errors())->flatten()->implode(' ');
            }
        }

        $this->newLine();
        $this->info('Assigned '.$done.' staff to '.$template->name.'.');

        if ($failed !== []) {
            $this->newLine();
            $this->warn(count($failed).' could not be assigned:');

            foreach ($failed as $failure) {
                $this->line('  '.$failure);
            }
        }

        $this->newLine();
        $this->line('Check a few profiles before relying on it — Schedule → Next 7 shifts.');

        return self::SUCCESS;
    }

    /**
     * The days this group actually works, as ISO weekday numbers.
     *
     * Returned as an override on each assignment rather than by editing the
     * template, because a template in use is locked — and rightly so, since
     * changing its days would retroactively rewrite who was absent on every
     * day already judged under it.
     *
     * @return array<int, int>|false false on a bad value
     */
    private function workingDays(): array|false
    {
        $given = $this->option('days');

        if (blank($given)) {
            return [];
        }

        $days = [];

        foreach (explode(',', (string) $given) as $part) {
            $day = (int) trim($part);

            if ($day < 1 || $day > 7) {
                $this->error('"'.trim($part).'" is not a weekday. Use 1 (Monday) to 7 (Sunday).');
                $this->line('Example: --days=1,2,3,4,5,6 for Monday to Saturday.');

                return false;
            }

            $days[] = $day;
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    /**
     * @param  array<int, int>  $days
     */
    private function describeDays(array $days): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

        if ($days === []) {
            return 'as the template defines';
        }

        if (count($days) === 7) {
            return 'every day';
        }

        return implode(', ', array_map(fn (int $d) => $names[$d], $days));
    }

    private function resolveTemplate(): ?AttendanceShiftTemplate
    {
        $given = $this->option('template');

        if (! $given) {
            $this->error('--template is required.');
            $this->newLine();
            $this->line('Available:');

            foreach (AttendanceShiftTemplate::active()->orderBy('name')->get() as $template) {
                $this->line('  '.$template->id.'  '.$template->name
                    .'  ('.$template->describeHours().', '.$template->pattern_type.')');
            }

            return null;
        }

        $template = is_numeric($given)
            ? AttendanceShiftTemplate::find($given)
            : AttendanceShiftTemplate::where('name', $given)->first();

        if ($template === null) {
            $this->error('No shift template matching "'.$given.'".');

            return null;
        }

        if ($template->isRetired()) {
            $this->error('"'.$template->name.'" is retired and cannot be assigned.');

            return null;
        }

        return $template;
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function targets(): \Illuminate\Support\Collection
    {
        // Leavers and exempt staff are excluded always: the resolver would
        // produce no shifts for them anyway, and an assignment nobody can be
        // judged against is just confusing history.
        $query = User::query()
            ->whereNull('left_at')
            ->where('attendance_exempt', false)
            ->orderBy('name');

        $roles = array_filter((array) $this->option('role'));
        $ids = array_filter((array) $this->option('user'));

        if ($ids !== []) {
            return $query->whereIn('id', $ids)->get();
        }

        if ($roles !== []) {
            return $query->whereHas('roles', fn ($q) => $q->whereIn('name', $roles))->get();
        }

        if (! $this->option('all')) {
            return collect();
        }

        return $query->get();
    }
}
