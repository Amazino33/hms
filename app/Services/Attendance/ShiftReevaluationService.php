<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceSetting;
use App\Models\Attendance\AttendanceShiftRecord;
use App\Models\AttendanceLog;
use App\Models\User;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Re-judges shifts whose inputs have changed since they were judged.
 *
 * Three things can invalidate a verdict after the fact: punches arriving late
 * from a device's offline buffer, a device link being voided as a mistake, and
 * a schedule change backdated over days already judged. In each case the
 * record on file is now answering a question with stale facts.
 *
 * It never edits. The old record is superseded and its fines voided with a
 * stated reason, and a new record is written — so somebody disputing a charge
 * in March can still see what the system believed in March and why it changed.
 */
class ShiftReevaluationService
{
    public function __construct(
        private readonly ShiftScheduleResolver $resolver,
        private readonly ShiftEvaluator $evaluator,
        private readonly PunchAttributor $attributor,
        private readonly PunchWindowAssigner $assigner,
        private readonly ShiftFinaliser $finaliser,
    ) {}

    /**
     * @return array{examined: int, changed: int, unchanged: int, skipped_paid: array<int, int>}
     */
    public function reevaluate(
        CarbonInterface $from,
        CarbonInterface $to,
        string $reason,
        ?User $onlyUser = null,
        ?User $actor = null,
    ): array {
        $start = $this->localDate($from);
        $end = $this->localDate($to);

        $counts = ['examined' => 0, 'changed' => 0, 'unchanged' => 0, 'skipped_paid' => []];

        if ($end->lessThan($start)) {
            return $counts;
        }

        $this->attributor->preload();

        $users = $onlyUser !== null
            ? collect([$onlyUser])
            : User::whereIn('id', AttendanceShiftRecord::current()
                // whereDate on both sides rather than whereBetween: shift_date
                // is a date cast stored as a datetime, and "2026-10-05
                // 00:00:00" sorts after the bare bound "2026-10-05", so a
                // single-day range would match nothing at all.
                ->whereDate('shift_date', '>=', $start->toDateString())
                ->whereDate('shift_date', '<=', $end->toDateString())
                ->distinct()
                ->pluck('user_id'))->get();

        $punches = $this->punchesBetween($start->subDay(), $end->addDays(2));
        $punchesByUser = $this->attributor->groupByOwner($punches);

        foreach ($users as $user) {
            $counts = $this->reevaluateUser(
                $user,
                $start,
                $end,
                $reason,
                $actor,
                $punchesByUser->get($user->id, collect()),
                $counts,
            );
        }

        $this->attributor->flush();

        return $counts;
    }

    private function reevaluateUser(
        User $user,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $reason,
        ?User $actor,
        Collection $userPunches,
        array $counts,
    ): array {
        $records = AttendanceShiftRecord::current()
            ->where('user_id', $user->id)
            ->whereDate('shift_date', '>=', $start->toDateString())
            ->whereDate('shift_date', '<=', $end->toDateString())
            ->with('fines')
            ->get()
            ->keyBy(fn (AttendanceShiftRecord $r) => $r->scheduled_start_at->format('Y-m-d H:i:s'));

        if ($records->isEmpty()) {
            return $counts;
        }

        $shifts = $this->resolver->expectedShifts($user, $start, $end);

        if ($shifts->isEmpty()) {
            return $counts;
        }

        $reference = AttendanceSetting::forDate($start) ?? AttendanceSetting::current();

        if ($reference === null) {
            return $counts;
        }

        $assignment = $this->assigner->assign($shifts, $userPunches, $reference);

        foreach ($shifts->values() as $index => $shift) {
            $key = $shift->startsAt->utc()->format('Y-m-d H:i:s');
            $existing = $records->get($key);

            if ($existing === null) {
                continue;
            }

            $counts['examined']++;

            // A charge already carried into payroll cannot be withdrawn here:
            // voiding it would silently disagree with a payslip somebody has
            // already been handed. Reported so a person can deal with it.
            $paid = $existing->fines->first(fn ($f) => $f->payroll_run_id !== null);

            if ($paid !== null) {
                $counts['skipped_paid'][] = $existing->id;

                continue;
            }

            $settings = AttendanceSetting::forDate(
                CarbonImmutable::parse($shift->shiftDate, VenueTime::TIMEZONE)
            ) ?? $reference;

            $evaluated = $this->evaluator->evaluate(
                $shift,
                $settings,
                $assignment['assigned'][$index] ?? collect(),
                $this->attributor->hasLinkOn($user->id, CarbonImmutable::parse($shift->shiftDate, VenueTime::TIMEZONE)),
            );

            if ($this->unchanged($existing, $evaluated)) {
                $counts['unchanged']++;

                continue;
            }

            $this->supersede($existing, $evaluated, $settings, $reason, $actor);
            $counts['changed']++;
        }

        return $counts;
    }

    /**
     * Swap one verdict for another, atomically.
     *
     * Order matters: the old record releases its current_key before the new
     * one claims it, or the unique index rejects the insert and the shift is
     * left with no current record at all.
     */
    private function supersede(
        AttendanceShiftRecord $old,
        EvaluatedShift $evaluated,
        AttendanceSetting $settings,
        string $reason,
        ?User $actor,
    ): void {
        DB::transaction(function () use ($old, $evaluated, $settings, $reason, $actor) {
            $old->forceFill([
                'superseded_at' => now(),
                'supersede_reason' => $reason,
                'current_key' => null,
            ])->save();

            foreach ($old->fines as $fine) {
                if ($fine->isVoided()) {
                    continue;
                }

                $fine->void('reevaluated: '.$reason, $actor);
            }

            $new = $this->finaliser->persist($evaluated, $settings, $evaluated->flags);

            if ($new !== null) {
                $old->forceFill(['superseded_by_id' => $new->id])->save();
            }
        });
    }

    /**
     * Whether a fresh evaluation says the same thing as the record on file.
     *
     * Compares the verdict and the money only. Re-running produces slightly
     * different incidentals — flag ordering, raw counts after a late punch —
     * and superseding over those would churn the history without changing
     * what anybody owes.
     */
    private function unchanged(AttendanceShiftRecord $record, EvaluatedShift $evaluated): bool
    {
        if ($record->outcome !== $evaluated->outcome) {
            return false;
        }

        if ($record->late_minutes !== $evaluated->lateMinutes) {
            return false;
        }

        if ($record->early_leave_minutes !== $evaluated->earlyLeaveMinutes) {
            return false;
        }

        if ($record->clock_in_at?->getTimestamp() !== $evaluated->clockInAt?->getTimestamp()) {
            return false;
        }

        if ($record->clock_out_at?->getTimestamp() !== $evaluated->clockOutAt?->getTimestamp()) {
            return false;
        }

        $existing = $record->fines->whereNull('voided_at')
            ->map(fn ($f) => $f->type.':'.$f->kind.':'.$f->amount)->sort()->values()->all();

        $fresh = collect($evaluated->fines)
            ->map(fn (array $f) => $f['type'].':'.$f['kind'].':'.$f['amount'])->sort()->values()->all();

        return $existing === $fresh;
    }

    /**
     * Which shift dates a punch could possibly affect.
     *
     * Deliberately a day either side: a punch at 00:30 belongs to yesterday's
     * overnight shift, and one at 23:50 may belong to today's.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function rangeForPunch(AttendanceLog $punch): array
    {
        $date = $this->localDate($punch->punch_time);

        return [$date->subDay(), $date->addDay()];
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

        return CarbonImmutable::instance($value->toDateTime())
            ->setTimezone(VenueTime::TIMEZONE)
            ->startOfDay();
    }
}
