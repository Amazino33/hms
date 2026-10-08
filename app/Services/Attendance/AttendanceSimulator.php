<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceSetting;
use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\AttendanceLog;
use App\Models\User;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Runs the rules over the past without touching anything.
 *
 * The point is to find out what a month of these fines would actually have
 * come to before anybody is told the rules exist. September 2026 showed
 * roughly 80% of days with no clock-out time — some of that is the old
 * calendar-day grouping splitting overnight shifts in half, and some of it is
 * people genuinely not clocking out. Those two have very different answers,
 * and guessing between them with real money is not an option.
 *
 * Writes nothing. Not by convention — ShiftEvaluator is pure and this only
 * reads, which an architecture test enforces.
 */
class AttendanceSimulator
{
    public function __construct(
        private readonly ShiftScheduleResolver $resolver,
        private readonly ShiftEvaluator $evaluator,
        private readonly PunchAttributor $attributor,
        private readonly PunchWindowAssigner $assigner,
    ) {}

    /**
     * @return array{
     *     rows: Collection<int, array<string, mixed>>,
     *     perStaff: Collection<int, array<string, mixed>>,
     *     totals: array<string, mixed>,
     *     warnings: array<int, string>
     * }
     */
    public function run(
        CarbonInterface $from,
        CarbonInterface $to,
        bool $assumeCurrentSchedules = false,
    ): array {
        $start = $this->localDate($from);
        $end = $this->localDate($to);

        $warnings = $this->warningsFor($start, $end);

        $settings = AttendanceSetting::forDate($end) ?? AttendanceSetting::current();

        if ($settings === null) {
            return [
                'rows' => collect(),
                'perStaff' => collect(),
                'totals' => [],
                'warnings' => array_merge($warnings, [
                    'No attendance rules have ever been saved, so there are no figures to simulate against.',
                ]),
            ];
        }

        $this->attributor->preload();

        $punches = $this->punchesBetween($start->subDay(), $end->addDays(2));
        $punchesByUser = $this->attributor->groupByOwner($punches);

        $rows = collect();

        foreach (User::where('attendance_exempt', false)->orderBy('name')->get() as $user) {
            $shifts = $this->shiftsFor($user, $start, $end, $assumeCurrentSchedules);

            if ($shifts->isEmpty()) {
                continue;
            }

            $userPunches = $punchesByUser->get($user->id, collect());
            $assignment = $this->assigner->assign($shifts, $userPunches, $settings);

            foreach ($shifts->values() as $index => $shift) {
                $forDate = AttendanceSetting::forDate(
                    CarbonImmutable::parse($shift->shiftDate, VenueTime::TIMEZONE)
                ) ?? $settings;

                $evaluated = $this->evaluator->evaluate(
                    $shift,
                    $forDate,
                    $assignment['assigned'][$index] ?? collect(),
                    $this->attributor->hasLinkOn($user->id, CarbonImmutable::parse($shift->shiftDate, VenueTime::TIMEZONE)),
                );

                $rows->push($this->rowFor($user, $evaluated));
            }
        }

        $this->attributor->flush();

        return [
            'rows' => $rows,
            'perStaff' => $this->perStaff($rows),
            'totals' => $this->totals($rows, $start, $end),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return Collection<int, ExpectedShift>
     */
    private function shiftsFor(User $user, CarbonImmutable $start, CarbonImmutable $end, bool $assume): Collection
    {
        if (! $assume) {
            return $this->resolver->expectedShifts($user, $start, $end);
        }

        $current = AttendanceShiftAssignment::query()
            ->with('template')
            ->where('user_id', $user->id)
            ->orderByDesc('effective_from')
            ->first();

        if ($current === null) {
            return collect();
        }

        return $this->resolver->projectAssignment($user, $current, $start, $end);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(User $user, EvaluatedShift $evaluated): array
    {
        $shift = $evaluated->shift;

        return [
            'user_id' => $user->id,
            'staff' => $user->name,
            'shift_date' => $shift->shiftDate,
            'scheduled_start' => $shift->startsAt->format('H:i'),
            'scheduled_end' => $shift->endsAt->format('H:i')
                .($shift->crossesMidnight() ? ' (+1d)' : ''),
            'clock_in' => $evaluated->clockInAt?->setTimezone(VenueTime::TIMEZONE)->format('H:i'),
            'clock_out' => $evaluated->clockOutAt?->setTimezone(VenueTime::TIMEZONE)->format('H:i'),
            'late_minutes' => $evaluated->lateMinutes,
            'early_leave_minutes' => $evaluated->earlyLeaveMinutes,
            'outcome' => $evaluated->outcome,
            'raw_punches' => $evaluated->rawPunches->count(),
            'kept_punches' => $evaluated->keptPunches->count(),
            'fines' => $evaluated->totalFines(),
            'pay_deductions' => $evaluated->totalPayDeductions(),
            'fine_types' => collect($evaluated->fines)->pluck('type')->implode(', '),
            'flags' => implode(', ', $evaluated->flags),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function perStaff(Collection $rows): Collection
    {
        return $rows->groupBy('staff')->map(function (Collection $staffRows, string $staff) {
            $count = fn (string $outcome) => $staffRows->where('outcome', $outcome)->count();

            return [
                'staff' => $staff,
                'scheduled' => $staffRows->count(),
                'present' => $count('present'),
                'late' => $count('late') + $count('late_and_early_leave'),
                'late_relief' => $count('late_relief'),
                'early_leave' => $count('early_leave') + $count('late_and_early_leave'),
                'no_clockout' => $count('no_clockout') + $count('late_no_clockout'),
                'absent' => $count('absent'),
                'unlinked' => $count('unlinked'),
                'total_fines' => $staffRows->sum('fines'),
                'total_pay_deductions' => $staffRows->sum('pay_deductions'),
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function totals(Collection $rows, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $byType = [];

        foreach (['late', 'late_relief', 'early_leave', 'no_clockout', 'absent', 'absence_day_pay'] as $type) {
            $matching = $rows->filter(fn (array $r) => str_contains($r['fine_types'], $type));
            $byType[$type] = $matching->count();
        }

        return [
            'shifts' => $rows->count(),
            'by_type' => $byType,
            'total_fines' => $rows->sum('fines'),
            'total_pay_deductions' => $rows->sum('pay_deductions'),
            'shift_window_no_clockout' => $rows->whereIn('outcome', ['no_clockout', 'late_no_clockout'])->count(),
            'calendar_day_no_out_time' => $this->calendarDayNoOutTime($start, $end),
        ];
    }

    /**
     * How many days the OLD calendar-day view would have called "no out time".
     *
     * This is the comparison the whole exercise exists for. The daily view
     * groups by calendar date, so a bartender who starts Tuesday 08:00 and
     * finishes Wednesday 08:00 appears as two days with one punch each — two
     * apparent no-clock-outs from one complete shift. Set against the
     * shift-window figure, the gap is how much of the 80% was an artefact and
     * how much is people genuinely not clocking out.
     */
    private function calendarDayNoOutTime(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $rows = DB::table('attendance_logs')
            ->whereNotNull('biometric_id')
            ->where('punch_time', '>=', $start->startOfDay()->utc())
            ->where('punch_time', '<', $end->addDay()->startOfDay()->utc())
            ->get(['biometric_id', 'punch_time']);

        $byDay = [];

        foreach ($rows as $row) {
            $day = CarbonImmutable::parse($row->punch_time, 'UTC')
                ->setTimezone(VenueTime::TIMEZONE)
                ->toDateString();

            $byDay[$row->biometric_id.'|'.$day] = ($byDay[$row->biometric_id.'|'.$day] ?? 0) + 1;
        }

        return count(array_filter($byDay, fn (int $n) => $n < 2));
    }

    /**
     * @return array<int, string>
     */
    private function warningsFor(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $warnings = [];

        $boundary = CarbonImmutable::parse(config('attendance.timestamp_fix_at'), 'UTC');

        if ($start->utc()->lessThan($boundary)) {
            $warnings[] = 'This range reaches before '.$boundary->setTimezone(VenueTime::TIMEZONE)->format('j M Y H:i')
                .', when punches were stored an hour fast (the device\'s Lagos clock was saved as if it were UTC). '
                .'Arrival times before that point read one hour LATER than they really were, so lateness in this '
                .'period is overstated. Treat those figures as indicative only.';
        }

        return $warnings;
    }

    /**
     * @return Collection<int, AttendanceLog>
     */
    private function punchesBetween(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return AttendanceLog::query()
            ->whereNotNull('biometric_id')
            ->where('punch_time', '>=', $from->startOfDay()->utc())
            ->where('punch_time', '<', $to->addDay()->startOfDay()->utc())
            ->orderBy('punch_time')
            ->get();
    }

    private function localDate(CarbonInterface|string $value): CarbonImmutable
    {
        if (is_string($value)) {
            return CarbonImmutable::parse($value, VenueTime::TIMEZONE)->startOfDay();
        }

        return CarbonImmutable::parse($value->format('Y-m-d'), VenueTime::TIMEZONE)->startOfDay();
    }
}
