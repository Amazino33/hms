<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\AttendanceShiftTemplate;
use App\Models\User;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Putting a person on a schedule, and moving them off it.
 *
 * Assignments are append-only and must never overlap: two assignments live on
 * the same date would make "what was this person due to work on Tuesday"
 * ambiguous, and Phase 2 would have to guess which one to fine against.
 */
class ShiftAssignmentService
{
    /**
     * Put a person on a template from a date, closing whatever they are on
     * now. One transaction: a close without its replacement leaves somebody
     * unscheduled and therefore silently unfined.
     *
     * @throws ValidationException
     */
    public function assign(
        User $user,
        AttendanceShiftTemplate $template,
        CarbonInterface $effectiveFrom,
        ?array $weeklyDaysOverride = null,
        ?CarbonInterface $rotationAnchorDate = null,
        ?string $reason = null,
        ?User $actor = null,
    ): AttendanceShiftAssignment {
        $from = $this->localDate($effectiveFrom);

        $this->assertTemplateUsable($template);
        $this->assertAnchorPresent($template, $rotationAnchorDate);

        return DB::transaction(function () use ($user, $template, $from, $weeklyDaysOverride, $rotationAnchorDate, $reason, $actor) {
            $this->closeAssignmentsFrom($user, $from, $actor);
            $this->assertNoOverlap($user, $from);

            return AttendanceShiftAssignment::create([
                'user_id' => $user->id,
                'attendance_shift_template_id' => $template->id,
                'effective_from' => $from->toDateString(),
                'weekly_days_override' => $this->normaliseDays($template, $weeklyDaysOverride),
                'rotation_anchor_date' => $template->isRotation()
                    ? $this->localDate($rotationAnchorDate)->toDateString()
                    : null,
                'reason' => $reason,
                'created_by' => $actor?->id,
            ]);
        });
    }

    /**
     * Take somebody off the rota from a date, without putting them on
     * anything else — a leaver, or someone moving to a role that is not
     * tracked.
     */
    public function end(AttendanceShiftAssignment $assignment, CarbonInterface $lastDay, ?User $actor = null): AttendanceShiftAssignment
    {
        $last = $this->localDate($lastDay);

        if ($last->lessThan($this->localDate($assignment->effective_from))) {
            throw ValidationException::withMessages([
                'effective_to' => 'A schedule cannot end before it started.',
            ]);
        }

        $assignment->forceFill([
            'effective_to' => $last->toDateString(),
            'ended_by' => $actor?->id,
        ])->save();

        return $assignment;
    }

    public function currentFor(User $user, ?CarbonInterface $on = null): ?AttendanceShiftAssignment
    {
        $date = $this->localDate($on ?? now());

        return AttendanceShiftAssignment::query()
            ->with('template')
            ->where('user_id', $user->id)
            ->covering($date)
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Non-exempt staff who are not scheduled today — the people a fine would
     * silently skip. Excludes leavers.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function staffWithoutSchedule(): \Illuminate\Support\Collection
    {
        $today = $this->localDate(now())->toDateString();

        return User::query()
            ->whereNull('left_at')
            ->where('attendance_exempt', false)
            ->whereDoesntHave('attendanceShiftAssignments', function ($query) use ($today) {
                $query->whereDate('effective_from', '<=', $today)
                    ->where(function ($q) use ($today) {
                        $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
                    });
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * Close anything that would still be running on the changeover date.
     *
     * The previous assignment ends the day before, so the two never share a
     * date.
     *
     * An assignment the changeover reaches back past — including one starting
     * on the very same day, which is what re-assigning somebody looks like —
     * is closed to the day BEFORE its own start. That reads as a backwards
     * range on purpose: it is how an assignment that never applied for a
     * single day is recorded, since nothing here may be deleted. Closing it
     * to its own start date instead would leave it covering that one day, and
     * the overlap check would then refuse the replacement it was making room
     * for.
     */
    private function closeAssignmentsFrom(User $user, CarbonImmutable $from, ?User $actor): void
    {
        $open = AttendanceShiftAssignment::query()
            ->where('user_id', $user->id)
            ->overlapping($from, null)
            ->get();

        foreach ($open as $assignment) {
            $start = $this->localDate($assignment->effective_from);
            $lastDay = $from->subDay();

            if ($lastDay->lessThan($start)) {
                // Superseded before it ever took effect. Ends the day before
                // it began, so covering() and overlapping() both exclude it.
                $assignment->forceFill([
                    'effective_to' => $start->subDay()->toDateString(),
                    'ended_by' => $actor?->id,
                ])->save();

                continue;
            }

            $assignment->forceFill([
                'effective_to' => $lastDay->toDateString(),
                'ended_by' => $actor?->id,
            ])->save();
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertNoOverlap(User $user, CarbonImmutable $from): void
    {
        $clash = AttendanceShiftAssignment::query()
            ->where('user_id', $user->id)
            ->covering($from)
            ->first();

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'effective_from' => 'This person already has a schedule covering '
                    .$from->toDateString().' (assignment #'.$clash->id.').',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertAnchorPresent(AttendanceShiftTemplate $template, ?CarbonInterface $anchor): void
    {
        if ($template->isRotation() && $anchor === null) {
            throw ValidationException::withMessages([
                'rotation_anchor_date' => 'A rotation needs an anchor date — pick a day this person is working.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertTemplateUsable(AttendanceShiftTemplate $template): void
    {
        if ($template->isRetired()) {
            throw ValidationException::withMessages([
                'attendance_shift_template_id' => 'That shift template has been retired — choose a current one.',
            ]);
        }
    }

    /**
     * An override only means anything on a weekly template, and an override
     * identical to the template's own days is noise: storing it would make a
     * later change to the template silently not apply to this person.
     */
    private function normaliseDays(AttendanceShiftTemplate $template, ?array $days): ?array
    {
        if (! $template->isWeekly() || $days === null) {
            return null;
        }

        $days = array_values(array_unique(array_map('intval', array_filter($days, fn ($d) => $d !== null && $d !== ''))));
        sort($days);

        $templateDays = array_map('intval', $template->weekly_days ?? []);
        sort($templateDays);

        return $days === $templateDays ? null : $days;
    }

    private function localDate(CarbonInterface|string $value): CarbonImmutable
    {
        if (is_string($value)) {
            return CarbonImmutable::parse($value, VenueTime::TIMEZONE)->startOfDay();
        }

        return CarbonImmutable::parse($value->format('Y-m-d'), VenueTime::TIMEZONE)->startOfDay();
    }
}
