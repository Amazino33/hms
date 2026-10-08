<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceSetting;
use App\Models\AttendanceLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Turns one expected shift plus the punches around it into a verdict.
 *
 * Pure. It reads nothing and writes nothing — everything it needs is passed
 * in, and it hands back a value object. That is not tidiness for its own sake:
 * this is the code that decides what comes out of somebody's wages, so it has
 * to be runnable over a past month without touching a row, and it has to give
 * the same answer twice.
 *
 * Nothing here consults punch_state. The device records 0/1/4/5 depending on
 * which key a person pressed, and production shows 1,026 check-ins against 598
 * check-outs over 60 days — people simply do not press it reliably. Order is
 * the only thing that can be trusted: first punch in, last punch out.
 */
class ShiftEvaluator
{
    public const FLAG_UNLINKED = 'unlinked_scheduled';

    public const FLAG_SINGLE_PUNCH_LATE_HALF = 'single_punch_late_half';

    public const FLAG_VERY_LATE = 'very_late';

    public const FLAG_DAY_PAY_NOT_CONFIGURED = 'day_pay_not_configured';

    public const FLAG_DEVICE_SILENT = 'device_silent';

    public const FLAG_SUSPECTED_OUTAGE = 'suspected_outage';

    /**
     * @param  Collection<int, AttendanceLog>  $punches  punches already assigned to this shift
     * @param  bool  $hasLink  whether this person had any badge at all on the shift date
     */
    public function evaluate(
        ExpectedShift $shift,
        AttendanceSetting $settings,
        Collection $punches,
        bool $hasLink = true,
    ): EvaluatedShift {
        [$windowStart, $windowEnd] = self::windowFor($shift, $settings);

        $raw = $punches->sortBy(fn (AttendanceLog $p) => $p->punch_time->getTimestamp())->values();
        $kept = $this->collapse($raw, $settings->duplicate_punch_window_minutes);

        return match ($kept->count()) {
            0 => $this->noPunches($shift, $settings, $raw, $kept, $hasLink, $windowStart, $windowEnd),
            1 => $this->singlePunch($shift, $settings, $raw, $kept, $windowStart, $windowEnd),
            default => $this->fullShift($shift, $settings, $raw, $kept, $windowStart, $windowEnd),
        };
    }

    /**
     * The span of time a punch may fall in and still belong to this shift.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function windowFor(ExpectedShift $shift, AttendanceSetting $settings): array
    {
        return [
            $shift->startsAt->subMinutes($settings->window_before_minutes),
            $shift->endsAt->addMinutes($settings->window_after_minutes),
        ];
    }

    /**
     * Drop punches that are really the same punch.
     *
     * People touch the reader twice when it does not beep, and a supervisor
     * standing at the door can produce three reads in a minute. Measured from
     * the last KEPT punch rather than the previous raw one, so five touches
     * thirty seconds apart collapse to one rather than ratcheting forward.
     *
     * @param  Collection<int, AttendanceLog>  $punches
     * @return Collection<int, AttendanceLog>
     */
    private function collapse(Collection $punches, int $windowMinutes): Collection
    {
        $kept = collect();
        $lastKept = null;

        foreach ($punches as $punch) {
            if ($lastKept === null) {
                $kept->push($punch);
                $lastKept = $punch->punch_time;

                continue;
            }

            $gap = $lastKept->diffInSeconds($punch->punch_time, false) / 60;

            if ($gap < $windowMinutes) {
                continue;
            }

            $kept->push($punch);
            $lastKept = $punch->punch_time;
        }

        return $kept->values();
    }

    private function noPunches(
        ExpectedShift $shift,
        AttendanceSetting $settings,
        Collection $raw,
        Collection $kept,
        bool $hasLink,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): EvaluatedShift {
        // No badge means the system had no way to see this person, which is
        // an administrative gap rather than an absence. Fining it would
        // charge somebody ₦3,000 for the office not having linked them.
        if (! $hasLink) {
            return $this->result(
                $shift, 'unlinked', null, null, null, null, 0, 0,
                $raw, $kept, [], [self::FLAG_UNLINKED], $windowStart, $windowEnd,
            );
        }

        $fines = [
            ['type' => 'absent', 'kind' => 'fine', 'amount' => (int) $settings->fine_absent],
        ];

        $flags = [];

        // The day-pay deduction is a real figure the owner has to decide. An
        // unset one must not silently become zero, or absences quietly stop
        // costing what they were announced to cost.
        if ($settings->absence_day_pay_amount !== null) {
            $fines[] = ['type' => 'absence_day_pay', 'kind' => 'pay_deduction', 'amount' => (int) $settings->absence_day_pay_amount];
        } else {
            $flags[] = self::FLAG_DAY_PAY_NOT_CONFIGURED;
        }

        return $this->result(
            $shift, 'absent', null, null, null, null, 0, 0,
            $raw, $kept, $fines, $flags, $windowStart, $windowEnd,
        );
    }

    /**
     * One punch: they came and never clocked out, or they only clocked out.
     *
     * The midpoint decides which. A single punch in the first half is somebody
     * arriving; one in the second half is somebody leaving, and charging them
     * for lateness on it would read their departure as their arrival — a
     * wildly wrong fine from a single missed press.
     */
    private function singlePunch(
        ExpectedShift $shift,
        AttendanceSetting $settings,
        Collection $raw,
        Collection $kept,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): EvaluatedShift {
        $punch = $kept->first();
        $at = CarbonImmutable::instance($punch->punch_time->toDateTime());
        $midpoint = $shift->startsAt->addMinutes((int) round($shift->durationMinutes() / 2));

        $fines = [
            ['type' => 'no_clockout', 'kind' => 'fine', 'amount' => (int) $settings->fine_no_clockout],
        ];

        if ($at->greaterThanOrEqualTo($midpoint)) {
            return $this->result(
                $shift, 'no_clockout', null, $at, null, $punch->id, 0, 0,
                $raw, $kept, $fines, [self::FLAG_SINGLE_PUNCH_LATE_HALF], $windowStart, $windowEnd,
            );
        }

        $lateMinutes = $this->minutesLate($shift, $at);
        $isLate = $lateMinutes > $settings->grace_minutes;

        if (! $isLate) {
            return $this->result(
                $shift, 'no_clockout', $at, null, $punch->id, null, $lateMinutes, 0,
                $raw, $kept, $fines, [], $windowStart, $windowEnd,
            );
        }

        $fines[] = $this->latenessFine($shift, $settings);

        return $this->result(
            $shift, 'late_no_clockout', $at, null, $punch->id, null, $lateMinutes, 0,
            $raw, $kept, $fines, [], $windowStart, $windowEnd,
        );
    }

    private function fullShift(
        ExpectedShift $shift,
        AttendanceSetting $settings,
        Collection $raw,
        Collection $kept,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): EvaluatedShift {
        $in = $kept->first();
        $out = $kept->last();

        $inAt = CarbonImmutable::instance($in->punch_time->toDateTime());
        $outAt = CarbonImmutable::instance($out->punch_time->toDateTime());

        $lateMinutes = $this->minutesLate($shift, $inAt);
        $earlyMinutes = $this->minutesEarly($shift, $outAt);

        $isLate = $lateMinutes > $settings->grace_minutes;
        $isEarly = $earlyMinutes > $settings->early_leave_grace_minutes;

        $fines = [];
        $flags = [];

        if ($isLate) {
            $fines[] = $this->latenessFine($shift, $settings);

            // Arriving after half the shift has gone is still only a late
            // fine under the current rules, but it is odd enough to be worth
            // somebody's eye — usually a wrong rotation or an unrecorded swap.
            $midpoint = $shift->startsAt->addMinutes((int) round($shift->durationMinutes() / 2));

            if ($inAt->greaterThanOrEqualTo($midpoint)) {
                $flags[] = self::FLAG_VERY_LATE;
            }
        }

        if ($isEarly) {
            $fines[] = ['type' => 'early_leave', 'kind' => 'fine', 'amount' => (int) $settings->fine_early_leave];
        }

        $outcome = match (true) {
            $isLate && $isEarly => 'late_and_early_leave',
            $isLate => $shift->isHandover ? 'late_relief' : 'late',
            $isEarly => 'early_leave',
            default => 'present',
        };

        return $this->result(
            $shift, $outcome, $inAt, $outAt, $in->id, $out->id, $lateMinutes, $earlyMinutes,
            $raw, $kept, $fines, $flags, $windowStart, $windowEnd,
        );
    }

    /**
     * Late on a handover shift costs more and replaces the ordinary late fine
     * rather than adding to it — the person being relieved cannot leave, so
     * one lateness has two victims.
     *
     * @return array{type: string, kind: string, amount: int}
     */
    private function latenessFine(ExpectedShift $shift, AttendanceSetting $settings): array
    {
        return $shift->isHandover
            ? ['type' => 'late_relief', 'kind' => 'fine', 'amount' => (int) $settings->fine_late_relief]
            : ['type' => 'late', 'kind' => 'fine', 'amount' => (int) $settings->fine_late];
    }

    /**
     * Whole minutes past the scheduled start, never negative — arriving early
     * is not negative lateness, it is simply not late.
     */
    private function minutesLate(ExpectedShift $shift, CarbonImmutable $in): int
    {
        return max(0, (int) floor($shift->startsAt->diffInSeconds($in, false) / 60));
    }

    private function minutesEarly(ExpectedShift $shift, CarbonImmutable $out): int
    {
        return max(0, (int) floor($out->diffInSeconds($shift->endsAt, false) / 60));
    }

    private function result(
        ExpectedShift $shift,
        string $outcome,
        ?CarbonImmutable $in,
        ?CarbonImmutable $out,
        ?int $inLogId,
        ?int $outLogId,
        int $lateMinutes,
        int $earlyMinutes,
        Collection $raw,
        Collection $kept,
        array $fines,
        array $flags,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): EvaluatedShift {
        return new EvaluatedShift(
            shift: $shift,
            outcome: $outcome,
            clockInAt: $in,
            clockOutAt: $out,
            clockInLogId: $inLogId,
            clockOutLogId: $outLogId,
            lateMinutes: $lateMinutes,
            earlyLeaveMinutes: $earlyMinutes,
            rawPunches: $raw,
            keptPunches: $kept,
            fines: $fines,
            flags: $flags,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
        );
    }
}
