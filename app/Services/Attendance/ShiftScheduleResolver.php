<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\User;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Works out which shifts a person was scheduled for over a date range.
 *
 * Pure computation. It reads assignments and templates and returns value
 * objects; it writes nothing, touches no punch data, and knows nothing about
 * fines. Phase 2 matches real punches against what this returns, so a bug
 * here becomes a wrong fine — hence the deliberate narrowness.
 *
 * Everything happens in Lagos wall-clock time. The app stores UTC and that
 * does not change, but "is Tuesday a working day" and "was 08:05 late" are
 * questions about the clock on the wall, not about UTC.
 */
class ShiftScheduleResolver
{
    /**
     * @return Collection<int, ExpectedShift>
     */
    public function expectedShifts(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        // Exempt staff (owner, CEO) are outside the system entirely: no
        // shifts means nothing to be absent from and nothing to be fined for.
        if ($user->attendance_exempt) {
            return collect();
        }

        $start = $this->localDate($from);
        $end = $this->localDate($to);

        // Nobody is scheduled on or after the day they left. Without this a
        // leaver keeps generating expected shifts indefinitely, and Phase 2
        // would mark them absent — ₦3,000 plus a day's pay — every day for
        // the rest of time, against a person who no longer works here.
        if ($user->left_at !== null) {
            // left_at is a true instant, so it needs converting rather than
            // date-stringing: 23:30 UTC is already tomorrow in Lagos, and
            // reading the UTC date would retire them a day early.
            $lastDay = $this->instantToLocalDate($user->left_at)->subDay();

            if ($lastDay->lessThan($start)) {
                return collect();
            }

            if ($lastDay->lessThan($end)) {
                $end = $lastDay;
            }
        }

        if ($end->lessThan($start)) {
            return collect();
        }

        $assignments = AttendanceShiftAssignment::query()
            ->with('template')
            ->where('user_id', $user->id)
            ->overlapping($start, $end)
            ->orderBy('effective_from')
            ->get();

        if ($assignments->isEmpty()) {
            return collect();
        }

        $shifts = collect();

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $assignment = $this->assignmentFor($assignments, $date);

            if ($assignment === null) {
                continue;
            }

            if (! $this->isOnDuty($assignment, $date)) {
                continue;
            }

            $shifts->push($this->buildShift($user, $assignment, $date));
        }

        return $shifts;
    }

    /**
     * The next N shifts from today, which is what the admin actually looks at
     * to confirm a rotation is anchored on the right day.
     *
     * Scans a bounded window rather than looping until it has N: a pattern
     * with a long off-stretch, or an assignment that ends next week, must not
     * spin forever looking for a shift that never comes.
     */
    public function nextShifts(User $user, int $count = 7, ?CarbonInterface $from = null): Collection
    {
        $start = $this->localDate($from ?? now());

        return $this->expectedShifts($user, $start, $start->addDays(90))->take($count);
    }

    /**
     * Project one assignment's pattern across a range, ignoring its own
     * effective dates.
     *
     * Only for the back-test. Most of the venue's history predates any
     * schedule existing at all, so "what would these rules have said about
     * September" needs the current pattern applied backwards — a rotation
     * projects from its anchor in both directions, which the positive modulo
     * already handles.
     *
     * Deliberately shares isOnDuty() and buildShift() with the live path
     * rather than reimplementing the pattern maths, so a simulation cannot
     * quietly disagree with the engine it is meant to be predicting.
     *
     * @return Collection<int, ExpectedShift>
     */
    public function projectAssignment(
        User $user,
        AttendanceShiftAssignment $assignment,
        CarbonInterface $from,
        CarbonInterface $to,
    ): Collection {
        if ($user->attendance_exempt || $assignment->template === null) {
            return collect();
        }

        $start = $this->localDate($from);
        $end = $this->localDate($to);
        $shifts = collect();

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            if ($this->isOnDuty($assignment, $date)) {
                $shifts->push($this->buildShift($user, $assignment, $date));
            }
        }

        return $shifts;
    }

    /**
     * The assignment in force on a date. Assignments never overlap (the
     * service enforces that), so the first match is the only match.
     */
    private function assignmentFor(Collection $assignments, CarbonImmutable $date): ?AttendanceShiftAssignment
    {
        return $assignments->first(function (AttendanceShiftAssignment $assignment) use ($date) {
            if ($date->lessThan($this->localDate($assignment->effective_from))) {
                return false;
            }

            return $assignment->effective_to === null
                || $date->lessThanOrEqualTo($this->localDate($assignment->effective_to));
        });
    }

    private function isOnDuty(AttendanceShiftAssignment $assignment, CarbonImmutable $date): bool
    {
        $template = $assignment->template;

        if ($template === null) {
            return false;
        }

        return $template->isRotation()
            ? $this->isRotationDayOn($assignment, $date)
            : $this->isWeeklyDayOn($assignment, $date);
    }

    private function isWeeklyDayOn(AttendanceShiftAssignment $assignment, CarbonImmutable $date): bool
    {
        // The override wins when set, including when deliberately set to no
        // days at all — that is a real state (suspended from the rota), not a
        // reason to fall back to the template.
        $days = $assignment->weekly_days_override ?? $assignment->template->weekly_days ?? [];

        return in_array((int) $date->isoWeekday(), array_map('intval', $days), true);
    }

    /**
     * n days on, n days off, counting from the anchor.
     *
     * The modulo must be positive: PHP's % keeps the sign of the dividend, so
     * a date 1 day *before* the anchor gives -1 % 2 = -1, which fails a
     * "< on_days" test and would silently mark every pre-anchor day as off.
     * Dates before the anchor are not hypothetical — a rotation created today
     * is routinely backdated to cover the month already worked.
     */
    private function isRotationDayOn(AttendanceShiftAssignment $assignment, CarbonImmutable $date): bool
    {
        $template = $assignment->template;
        $anchor = $assignment->rotation_anchor_date;

        if ($anchor === null) {
            return false;
        }

        $cycle = $template->fullCycleDays();

        if ($cycle <= 0 || $template->rotation_on_days < 1) {
            return false;
        }

        $elapsed = (int) $this->localDate($anchor)->startOfDay()
            ->diffInDays($date->startOfDay(), false);

        return $this->positiveModulo($elapsed, $cycle) < $template->rotation_on_days;
    }

    private function positiveModulo(int $value, int $modulus): int
    {
        return (($value % $modulus) + $modulus) % $modulus;
    }

    private function buildShift(User $user, AttendanceShiftAssignment $assignment, CarbonImmutable $date): ExpectedShift
    {
        $template = $assignment->template;

        $startsAt = CarbonImmutable::parse($date->toDateString(), VenueTime::TIMEZONE)
            ->setTimeFromTimeString($template->startTimeString());

        return new ExpectedShift(
            userId: $user->id,
            assignmentId: $assignment->id,
            templateId: $template->id,
            shiftDate: $startsAt->toDateString(),
            startsAt: $startsAt,
            endsAt: $startsAt->addMinutes($template->duration_minutes),
            isHandover: (bool) $template->is_handover,
        );
    }

    /**
     * The Lagos calendar date a real instant falls on.
     *
     * Distinct from localDate(), which deliberately reads a date cast's own
     * Y-m-d without shifting it. Using that on an instant would read the UTC
     * date and be a day out for anything between midnight and 01:00 Lagos.
     */
    private function instantToLocalDate(CarbonInterface|string $instant): CarbonImmutable
    {
        // users.left_at carries no cast on the model, so it arrives as a raw
        // string. It is stored UTC like every other instant, so it is parsed
        // as UTC rather than assumed local — reading it as Lagos would retire
        // somebody an hour early and, either side of midnight, a day early.
        $value = is_string($instant)
            ? CarbonImmutable::parse($instant, 'UTC')
            : CarbonImmutable::instance($instant->toDateTime());

        return $value->setTimezone(VenueTime::TIMEZONE)->startOfDay();
    }

    private function localDate(CarbonInterface|string $value): CarbonImmutable
    {
        if (is_string($value)) {
            return CarbonImmutable::parse($value, VenueTime::TIMEZONE)->startOfDay();
        }

        // Format-then-reparse rather than setTimezone: a date cast carries
        // midnight UTC, and shifting that to Lagos would move it to 01:00 the
        // same day — harmless here, but the same call on a real instant would
        // silently roll the date backwards for anything before 01:00.
        return CarbonImmutable::parse($value->format('Y-m-d'), VenueTime::TIMEZONE)->startOfDay();
    }
}
