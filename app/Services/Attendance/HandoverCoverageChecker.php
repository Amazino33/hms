<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\AttendanceShiftTemplate;
use App\Models\User;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Checks that a handover rota actually covers every day, exactly once.
 *
 * A handover shift is one where somebody is waiting to be relieved, so the
 * rota failing is not a paperwork problem: a gap means the bar is unmanned
 * and the person on duty cannot leave, and a double-up means two people turn
 * up and one of them is sent home. Both are easy to create by mis-anchoring a
 * rotation by a single day, and neither is visible from one person's screen.
 *
 * Advisory only — it reports, it never blocks a save. The admin may well be
 * mid-way through building a rota that is briefly incomplete.
 */
class HandoverCoverageChecker
{
    public function __construct(private readonly ShiftScheduleResolver $resolver) {}

    /**
     * Days in the window that are uncovered or double-covered.
     *
     * @return array{gaps: array<int, string>, overlaps: array<int, array{date: string, names: array<int, string>}>}
     */
    public function check(AttendanceShiftTemplate $template, int $days = 14, ?CarbonInterface $from = null): array
    {
        $start = CarbonImmutable::parse(($from ?? now())->format('Y-m-d'), VenueTime::TIMEZONE);
        $end = $start->addDays($days - 1);

        $byDate = $this->coverageByDate($template, $start, $end);

        $gaps = [];
        $overlaps = [];

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $names = $byDate[$date->toDateString()] ?? [];

            if ($names === []) {
                $gaps[] = $date->toDateString();

                continue;
            }

            if (count($names) > 1) {
                $overlaps[] = ['date' => $date->toDateString(), 'names' => $names];
            }
        }

        return ['gaps' => $gaps, 'overlaps' => $overlaps];
    }

    public function hasProblems(array $result): bool
    {
        return $result['gaps'] !== [] || $result['overlaps'] !== [];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function coverageByDate(AttendanceShiftTemplate $template, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $userIds = AttendanceShiftAssignment::query()
            ->where('attendance_shift_template_id', $template->id)
            ->overlapping($start, $end)
            ->pluck('user_id')
            ->unique();

        $byDate = [];

        /** @var Collection<int, User> $users */
        $users = User::whereIn('id', $userIds)->get();

        foreach ($users as $user) {
            foreach ($this->resolver->expectedShifts($user, $start, $end) as $shift) {
                if ($shift->templateId !== $template->id) {
                    continue;
                }

                $byDate[$shift->shiftDate][] = $user->name;
            }
        }

        return $byDate;
    }
}
