<?php

namespace App\Console\Commands;

use App\Models\Attendance\AttendanceDeviceLink;
use App\Models\Attendance\AttendanceDeviceUser;
use App\Models\Attendance\AttendanceSetting;
use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\AttendanceShiftRecord;
use App\Models\Attendance\AttendanceShiftTemplate;
use App\Models\AttendanceLog;
use App\Models\User;
use App\Models\ZktecoDevice;
use App\Services\Attendance\ShiftScheduleResolver;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Explains why the attendance board is showing what it is showing.
 *
 * An empty board has several possible causes that look identical from the
 * screen — no schedules, no links, shifts not finished yet, the scheduler not
 * running, or the engine's start date sitting ahead of the dates being looked
 * at. Each needs a different fix, and guessing between them wastes an evening.
 */
class AttendanceDoctor extends Command
{
    protected $signature = 'attendance:doctor {--date= : Check a specific shift date (Y-m-d, Lagos)}';

    protected $description = 'Explain why the attendance board is empty, and what to do about it';

    private array $blockers = [];

    public function handle(ShiftScheduleResolver $resolver): int
    {
        $date = CarbonImmutable::parse(
            $this->option('date') ?: CarbonImmutable::now(VenueTime::TIMEZONE)->toDateString(),
            VenueTime::TIMEZONE,
        )->startOfDay();

        $this->line('Checking attendance for '.$date->format('j M Y').' (Lagos)');
        $this->newLine();

        $this->checkRules();
        $this->checkTemplates();
        $this->checkSchedules($date);
        $this->checkDeviceLinks();
        $this->checkPunches($date);
        $this->checkEngineWindow($date);
        $this->checkScheduler();
        $this->checkRecords($date, $resolver);

        $this->newLine();

        if ($this->blockers === []) {
            $this->info('Nothing is blocking. If the board is still empty, the shifts for this date');
            $this->line('have simply not finished and been finalised yet.');

            return self::SUCCESS;
        }

        $this->error('What is stopping records appearing, in the order to fix it:');
        $this->newLine();

        foreach ($this->blockers as $i => $blocker) {
            $this->line('  '.($i + 1).'. '.$blocker['title']);

            foreach ($blocker['steps'] as $step) {
                $this->line('     '.$step);
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function checkRules(): void
    {
        $settings = AttendanceSetting::current();

        if ($settings === null) {
            $this->report('Attendance rules', false, 'none saved');
            $this->blockers[] = [
                'title' => 'No attendance rules have been saved.',
                'steps' => [
                    'Nothing can be judged without the fine amounts and windows.',
                    'Go to Attendance → Attendance Rules and press "Save as new version".',
                    'The defaults are already filled in; you do not have to change anything.',
                ],
            ];

            return;
        }

        $this->report('Attendance rules', true, 'version from '.$settings->effective_from->format('j M Y'));
    }

    private function checkTemplates(): void
    {
        $count = AttendanceShiftTemplate::active()->count();

        $this->report('Shift templates', $count > 0, $count.' active');

        if ($count === 0) {
            $this->blockers[] = [
                'title' => 'No shift templates exist.',
                'steps' => [
                    'Run: php artisan db:seed --class=AttendanceShiftTemplateSeeder --force',
                    'That creates "Day shift" and "Bartender 24h".',
                    'Add any others under Attendance → Shift Templates.',
                ],
            ];
        }
    }

    private function checkSchedules(CarbonImmutable $date): void
    {
        $covering = AttendanceShiftAssignment::query()
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString()))
            ->distinct()
            ->count('user_id');

        $total = AttendanceShiftAssignment::distinct()->count('user_id');
        $trackable = User::whereNull('left_at')->where('attendance_exempt', false)->count();

        $this->report('Staff scheduled on this date', $covering > 0, $covering.' of '.$trackable.' trackable staff');

        if ($covering > 0) {
            return;
        }

        $this->blockers[] = [
            'title' => 'Nobody has a shift schedule covering this date'
                .($total > 0 ? ' (though '.$total.' staff have one for other dates).' : '.'),
            'steps' => [
                'This is the usual reason the board is empty. Attendance is measured against',
                'the schedule, never against who happened to punch — so with no schedule there',
                'is genuinely nothing to judge, however many punches exist.',
                '',
                'For each member of staff: open their profile → Schedule → Change schedule.',
                'Pick the pattern, and backdate "Starts" to cover the period you want filled in.',
                'For bartenders set the anchor to a day they actually worked, then check the',
                '"Next 7 shifts" preview — one day out and the whole rota inverts.',
            ],
        ];
    }

    private function checkDeviceLinks(): void
    {
        $linked = AttendanceDeviceLink::active()->count();
        $unmatched = AttendanceDeviceUser::unmatched()->count();

        $this->report('Badges linked to staff', $linked > 0, $linked.' linked, '.$unmatched.' unmatched');

        if ($unmatched > 0) {
            $this->blockers[] = [
                'title' => $unmatched.' badge(s) are not linked to anybody.',
                'steps' => [
                    'Their punches cannot be attributed, and a scheduled person with no badge',
                    'is recorded as "Not linked" rather than absent — no fine, but no record either.',
                    'Fix under Attendance → Device Users (the number on the menu item).',
                ],
            ];
        }
    }

    private function checkPunches(CarbonImmutable $date): void
    {
        $count = AttendanceLog::query()
            ->where('punch_time', '>=', $date->startOfDay()->utc())
            ->where('punch_time', '<', $date->addDay()->startOfDay()->utc())
            ->count();

        $newest = AttendanceLog::max('punch_time');

        $this->report('Punches on this date', true, $count.' recorded');

        if ($newest !== null) {
            $this->line('    newest punch overall: '
                .CarbonImmutable::parse($newest, 'UTC')->setTimezone(VenueTime::TIMEZONE)->format('j M Y, H:i'));
        }
    }

    /**
     * The guard that stops the first scheduled run marking months of
     * untracked history absent — and the most common reason a past date
     * stays empty even once everything else is right.
     */
    private function checkEngineWindow(CarbonImmutable $date): void
    {
        $floor = CarbonImmutable::parse(config('attendance.engine_start_date'), VenueTime::TIMEZONE)->startOfDay();
        $ok = $date->greaterThanOrEqualTo($floor);

        $this->report('Within the engine window', $ok, 'engine starts '.$floor->format('j M Y'));

        if (! $ok) {
            $this->blockers[] = [
                'title' => 'This date is before the engine start date, so the routine run skips it.',
                'steps' => [
                    'That guard exists so the first run cannot mark months of untracked days absent.',
                    'To fill in history deliberately, use the backfill command instead:',
                    '  php artisan attendance:backfill --from='.$date->toDateString().' --to='.$date->toDateString().' --dry-run',
                    'Everything it writes is shadow and can never be charged.',
                ],
            ];
        }
    }

    private function checkScheduler(): void
    {
        $lastRecord = AttendanceShiftRecord::max('created_at');
        $device = ZktecoDevice::query()->max('last_push_at');

        $this->report(
            'Terminal contact',
            $device !== null,
            $device ? CarbonImmutable::parse($device, 'UTC')->setTimezone(VenueTime::TIMEZONE)->format('j M Y, H:i') : 'never',
        );

        $this->report(
            'Records ever created',
            $lastRecord !== null,
            $lastRecord ? 'last at '.CarbonImmutable::parse($lastRecord, 'UTC')->setTimezone(VenueTime::TIMEZONE)->format('j M Y, H:i') : 'none yet',
        );

        if ($lastRecord === null) {
            $this->blockers[] = [
                'title' => 'No attendance record has ever been created.',
                'steps' => [
                    'If everything above is green, the 15-minute job may not be running.',
                    'Check Laravel\'s scheduler is in cron (php artisan schedule:run, every minute):',
                    '  php artisan schedule:list',
                    'You can also run it by hand to test:',
                    '  php artisan attendance:finalise',
                ],
            ];
        }
    }

    private function checkRecords(CarbonImmutable $date, ShiftScheduleResolver $resolver): void
    {
        $records = AttendanceShiftRecord::current()->forDate($date->toDateString())->count();

        $expected = 0;

        foreach (User::where('attendance_exempt', false)->get() as $user) {
            $expected += $resolver->expectedShifts($user, $date, $date)->count();
        }

        $this->report('Shifts expected on this date', $expected > 0, $expected.' expected, '.$records.' judged');

        if ($expected > 0 && $records === 0) {
            $settings = AttendanceSetting::current();
            $delay = $settings?->finalise_delay_minutes ?? 60;
            $after = $settings?->window_after_minutes ?? 240;

            $this->blockers[] = [
                'title' => $expected.' shift(s) are expected but none have been judged yet.',
                'steps' => [
                    'A shift is only judged once its window has closed and the delay has passed —',
                    'roughly '.round(($after + $delay) / 60, 1).' hours after the scheduled end.',
                    'Today\'s shifts will appear this evening. For a past date, run:',
                    '  php artisan attendance:finalise',
                ],
            ];
        }
    }

    private function report(string $label, bool $ok, string $detail): void
    {
        $this->line(sprintf(
            '  %s %-32s %s',
            $ok ? '[ ok ]' : '[ !! ]',
            $label,
            $detail,
        ));
    }
}
