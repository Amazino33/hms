<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceFine;
use App\Models\Attendance\AttendanceReviewItem;
use App\Models\Attendance\AttendanceSetting;
use App\Models\Attendance\AttendanceShiftRecord;
use App\Models\AttendanceLog;
use App\Models\User;
use App\Models\ZktecoDevice;
use App\Support\VenueTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns expected shifts that are over and done with into judged records.
 *
 * The only writer of shift records and fines — re-evaluation routes its
 * inserts back through persist() here rather than carrying a second copy, so
 * there is exactly one place in the codebase that can charge anybody.
 *
 * Runs every fifteen minutes and is idempotent: a shift that already has a
 * current record is skipped, and the unique current_key makes a concurrent
 * double-run lose the insert rather than write two verdicts.
 */
class ShiftFinaliser
{
    /**
     * A day is called a suspected outage when at least this share of its
     * scheduled staff are absent. Half the building failing to turn up is
     * almost always the terminal, not the staff.
     */
    private const OUTAGE_ABSENCE_RATIO = 0.5;

    private const OUTAGE_MIN_SCHEDULED = 4;

    public function __construct(
        private readonly ShiftScheduleResolver $resolver,
        private readonly ShiftEvaluator $evaluator,
        private readonly PunchAttributor $attributor,
        private readonly PunchWindowAssigner $assigner,
    ) {}

    /**
     * Finalise everything that is ready, looking back a few days.
     *
     * The look-back is what catches a shift the device was silent for, and a
     * run that was skipped because the server was down.
     *
     * @return array{finalised: int, skipped_waiting: int, skipped_existing: int, review_items: int}
     */
    public function run(int $lookBackDays = 3, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance(($now ?? now())->toDateTime())->utc();

        $to = $this->localDate($now);
        $from = $to->subDays($lookBackDays);

        $floor = $this->engineStartDate();

        if ($from->lessThan($floor)) {
            $from = $floor;
        }

        $counts = ['finalised' => 0, 'skipped_waiting' => 0, 'skipped_existing' => 0, 'review_items' => 0];

        if ($from->greaterThan($to)) {
            return $counts;
        }

        $this->attributor->preload();

        $lastDeviceContact = $this->lastDeviceContact();
        $punches = $this->punchesBetween($from->subDay(), $to->addDays(2));
        $punchesByUser = $this->attributor->groupByOwner($punches);

        foreach ($this->trackableStaff() as $user) {
            $shifts = $this->resolver->expectedShifts($user, $from, $to);

            if ($shifts->isEmpty()) {
                continue;
            }

            $counts = $this->finaliseUser($user, $shifts, $punchesByUser->get($user->id, collect()), $now, $lastDeviceContact, $counts);
        }

        $this->attributor->flush();

        $counts['review_items'] += $this->flagSuspectedOutages($from, $to);

        return $counts;
    }

    /**
     * Judge a stretch of history that the routine run will never reach.
     *
     * Deliberately a separate entry point rather than a flag on run(): the
     * floor exists so the first scheduled run cannot mark months of untracked
     * days absent, and quietly loosening it would remove that protection for
     * every future run too. This asks for an explicit range, once.
     *
     * Everything it writes is shadow, whatever the settings say. The device
     * wait is skipped as well — a shift from last month is not waiting on a
     * buffered push.
     *
     * @return array{judged: int, skipped_existing: int, by_outcome: array<string, int>, fines: int, pay: int}
     */
    public function backfill(
        CarbonInterface $from,
        CarbonInterface $to,
        ?User $onlyUser = null,
        bool $dryRun = false,
    ): array {
        $start = $this->localDate($from);
        $end = $this->localDate($to);

        $counts = ['judged' => 0, 'skipped_existing' => 0, 'by_outcome' => [], 'fines' => 0, 'pay' => 0];

        if ($end->lessThan($start)) {
            return $counts;
        }

        $this->attributor->preload();

        $punches = $this->punchesBetween($start->subDay(), $end->addDays(2));
        $punchesByUser = $this->attributor->groupByOwner($punches);

        $staff = $onlyUser !== null ? collect([$onlyUser]) : $this->trackableStaff();

        foreach ($staff as $user) {
            $shifts = $this->resolver->expectedShifts($user, $start, $end);

            if ($shifts->isEmpty()) {
                continue;
            }

            $reference = AttendanceSetting::forDate($start) ?? AttendanceSetting::current();

            if ($reference === null) {
                continue;
            }

            $assignment = $this->assigner->assign($shifts, $punchesByUser->get($user->id, collect()), $reference);

            foreach ($shifts->values() as $index => $shift) {
                if ($this->alreadyRecorded($user->id, $shift->startsAt)) {
                    $counts['skipped_existing']++;

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

                $counts['by_outcome'][$evaluated->outcome] = ($counts['by_outcome'][$evaluated->outcome] ?? 0) + 1;
                $counts['fines'] += $evaluated->totalFines();
                $counts['pay'] += $evaluated->totalPayDeductions();

                if (! $dryRun && $this->persist($evaluated, $settings, $evaluated->flags, forceShadow: true) !== null) {
                    $counts['judged']++;
                }

                if ($dryRun) {
                    $counts['judged']++;
                }
            }
        }

        $this->attributor->flush();

        return $counts;
    }

    /**
     * @param  Collection<int, ExpectedShift>  $shifts
     * @param  Collection<int, AttendanceLog>  $userPunches
     */
    private function finaliseUser(
        User $user,
        Collection $shifts,
        Collection $userPunches,
        CarbonImmutable $now,
        ?CarbonImmutable $lastDeviceContact,
        array $counts,
    ): array {
        // Settings can change mid-range, so each shift is judged by the
        // version in force on its own date.
        $settingsFor = [];

        foreach ($shifts as $shift) {
            $settingsFor[$shift->shiftDate] ??= AttendanceSetting::forDate(
                CarbonImmutable::parse($shift->shiftDate, VenueTime::TIMEZONE)
            );
        }

        $reference = $settingsFor[$shifts->first()->shiftDate] ?? AttendanceSetting::current();

        if ($reference === null) {
            // No rules have ever been saved. Judging anybody against defaults
            // nobody agreed to would be inventing the figures.
            return $counts;
        }

        $assignment = $this->assigner->assign($shifts, $userPunches, $reference);

        foreach ($shifts->values() as $index => $shift) {
            $settings = $settingsFor[$shift->shiftDate] ?? $reference;

            [, $windowEnd] = ShiftEvaluator::windowFor($shift, $settings);

            $decision = $this->readiness($windowEnd, $settings, $now, $lastDeviceContact);

            if ($decision === 'wait') {
                $counts['skipped_waiting']++;

                continue;
            }

            if ($this->alreadyRecorded($user->id, $shift->startsAt)) {
                $counts['skipped_existing']++;

                continue;
            }

            $evaluated = $this->evaluator->evaluate(
                $shift,
                $settings,
                $assignment['assigned'][$index] ?? collect(),
                $this->attributor->hasLinkOn($user->id, CarbonImmutable::parse($shift->shiftDate, VenueTime::TIMEZONE)),
            );

            $flags = $evaluated->flags;

            if ($decision === 'device_silent') {
                $flags[] = ShiftEvaluator::FLAG_DEVICE_SILENT;
            }

            if ($this->persist($evaluated, $settings, $flags) !== null) {
                $counts['finalised']++;
            }
        }

        $counts['review_items'] += $this->recordUnscheduledPunches($user, $assignment['unassigned']);

        return $counts;
    }

    /**
     * Whether a shift can be judged yet.
     *
     * A terminal that has gone quiet is buffering punches, not reporting an
     * empty shift — finalising before they arrive marks everybody absent for
     * a day they all worked. So we wait on a silent device, but not forever:
     * past max_device_wait the shift is judged anyway and flagged, because an
     * unjudged shift is invisible rather than merely wrong.
     *
     * @return 'wait'|'ready'|'device_silent'
     */
    private function readiness(
        CarbonImmutable $windowEnd,
        AttendanceSetting $settings,
        CarbonImmutable $now,
        ?CarbonImmutable $lastDeviceContact,
    ): string {
        if ($now->lessThan($windowEnd->addMinutes($settings->finalise_delay_minutes))) {
            return 'wait';
        }

        $deviceHeardSinceWindow = $lastDeviceContact !== null
            && $lastDeviceContact->greaterThanOrEqualTo($windowEnd);

        if ($deviceHeardSinceWindow) {
            return 'ready';
        }

        if ($now->greaterThanOrEqualTo($windowEnd->addMinutes($settings->max_device_wait_minutes))) {
            return 'device_silent';
        }

        return 'wait';
    }

    /**
     * Write the verdict and its charges, together or not at all.
     *
     * The sole creator of AttendanceShiftRecord and AttendanceFine in the
     * codebase. A record without its fines would read as a shift that cost
     * nothing; fines without their record would be charges nobody could
     * explain.
     *
     * @param  array<int, string>  $flags
     */
    public function persist(
        EvaluatedShift $evaluated,
        AttendanceSetting $settings,
        array $flags = [],
        bool $forceShadow = false,
    ): ?AttendanceShiftRecord {
        $shift = $evaluated->shift;

        // forceShadow is for historical backfill. Judging the past can never
        // charge anybody: those staff were not told the rules existed on the
        // day, and a fine they had no chance to avoid is not a fine, it is a
        // deduction with a story attached.
        $isShadow = $forceShadow
            || ! AttendanceSetting::isLiveOn(CarbonImmutable::parse($shift->shiftDate, VenueTime::TIMEZONE));

        try {
            return DB::transaction(function () use ($evaluated, $settings, $flags, $shift, $isShadow) {
                $record = AttendanceShiftRecord::create([
                    'user_id' => $shift->userId,
                    'assignment_id' => $shift->assignmentId,
                    'template_id' => $shift->templateId,
                    'attendance_setting_id' => $settings->id,
                    'shift_date' => $shift->shiftDate,
                    'is_handover' => $shift->isHandover,
                    'scheduled_start_at' => $shift->startsAt->utc(),
                    'scheduled_end_at' => $shift->endsAt->utc(),
                    'window_start_at' => $evaluated->windowStart->utc(),
                    'window_end_at' => $evaluated->windowEnd->utc(),
                    'clock_in_at' => $evaluated->clockInAt?->utc(),
                    'clock_out_at' => $evaluated->clockOutAt?->utc(),
                    'clock_in_log_id' => $evaluated->clockInLogId,
                    'clock_out_log_id' => $evaluated->clockOutLogId,
                    'raw_punch_count' => $evaluated->rawPunches->count(),
                    'collapsed_punch_count' => $evaluated->keptPunches->count(),
                    'late_minutes' => $evaluated->lateMinutes,
                    'early_leave_minutes' => $evaluated->earlyLeaveMinutes,
                    'outcome' => $evaluated->outcome,
                    'review_flags' => array_values(array_unique($flags)),
                    'finalised_at' => now(),
                    'current_key' => AttendanceShiftRecord::currentKeyFor($shift->userId, $shift->startsAt->utc()),
                ]);

                foreach ($evaluated->fines as $fine) {
                    AttendanceFine::create([
                        'shift_record_id' => $record->id,
                        'user_id' => $shift->userId,
                        'shift_date' => $shift->shiftDate,
                        'kind' => $fine['kind'],
                        'type' => $fine['type'],
                        'amount' => $fine['amount'],
                        'attendance_setting_id' => $settings->id,
                        'is_shadow' => $isShadow,
                    ]);
                }

                return $record;
            });
        } catch (QueryException $e) {
            // The unique current_key rejected it, which means a concurrent run
            // got there first. That is the index doing its job, not a failure.
            if ($this->isDuplicateKey($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Punches from somebody who was linked and scheduled, falling in no shift
     * window at all. Usually a rotation anchored a day out or a swap nobody
     * recorded — a rota problem, not a person problem, so no fine.
     *
     * @param  Collection<int, AttendanceLog>  $unassigned
     */
    private function recordUnscheduledPunches(User $user, Collection $unassigned): int
    {
        $created = 0;

        foreach ($unassigned as $punch) {
            try {
                AttendanceReviewItem::create([
                    'user_id' => $user->id,
                    'attendance_log_id' => $punch->id,
                    'shift_date' => $this->localDate($punch->punch_time)->toDateString(),
                    'reason' => AttendanceReviewItem::UNSCHEDULED_PUNCH,
                    'detail' => $user->name.' punched at '
                        .$punch->punch_time->timezone(VenueTime::TIMEZONE)->format('j M Y H:i')
                        .' with no scheduled shift around it.',
                ]);
                $created++;
            } catch (QueryException $e) {
                // The (reason, log) unique index — this punch has already been
                // raised by an earlier run.
                if (! $this->isDuplicateKey($e)) {
                    throw $e;
                }
            }
        }

        return $created;
    }

    /**
     * Mark days where most of the roster is absent.
     *
     * One person absent is a person. Half the building absent is the device,
     * the network or a public holiday nobody recorded — and silently charging
     * forty people ₦3,000 each for it is the single worst thing this engine
     * could do.
     */
    private function flagSuspectedOutages(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $created = 0;

        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $day = $date->toDateString();

            $records = AttendanceShiftRecord::current()->forDate($day)->get();
            $scheduled = $records->count();

            if ($scheduled < self::OUTAGE_MIN_SCHEDULED) {
                continue;
            }

            $absent = $records->where('outcome', 'absent');

            if ($absent->count() / $scheduled < self::OUTAGE_ABSENCE_RATIO) {
                continue;
            }

            foreach ($absent as $record) {
                if ($record->hasFlag(ShiftEvaluator::FLAG_SUSPECTED_OUTAGE)) {
                    continue;
                }

                $record->forceFill([
                    'review_flags' => array_values(array_unique(
                        array_merge($record->review_flags ?? [], [ShiftEvaluator::FLAG_SUSPECTED_OUTAGE])
                    )),
                ])->save();
            }

            $exists = AttendanceReviewItem::where('reason', AttendanceReviewItem::SUSPECTED_OUTAGE)
                ->whereDate('shift_date', $day)
                ->exists();

            if ($exists) {
                continue;
            }

            AttendanceReviewItem::create([
                'shift_date' => $day,
                'reason' => AttendanceReviewItem::SUSPECTED_OUTAGE,
                'detail' => $absent->count().' of '.$scheduled.' scheduled staff were absent on '.$day
                    .'. Check whether the terminal was offline before treating these as real absences.',
            ]);

            $created++;
        }

        return $created;
    }

    private function alreadyRecorded(int $userId, CarbonImmutable $startsAt): bool
    {
        return AttendanceShiftRecord::where('current_key', AttendanceShiftRecord::currentKeyFor($userId, $startsAt->utc()))->exists();
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

    /**
     * @return Collection<int, User>
     */
    private function trackableStaff(): Collection
    {
        // left_at is not filtered here: the resolver stops at the leaving date
        // itself, and a person who left mid-range still has real shifts before
        // it that have to be judged.
        return User::query()->where('attendance_exempt', false)->get();
    }

    /**
     * The most recent sign of life from any terminal, on any endpoint.
     *
     * Three separate MAX()es rather than a GREATEST(): the test suite runs on
     * sqlite and production on MySQL, and the two disagree about GREATEST over
     * NULL datetimes. Getting this wrong fails open — it would look like the
     * device had been heard from, and finalise a buffered shift as absent.
     */
    private function lastDeviceContact(): ?CarbonImmutable
    {
        $stamps = [];

        foreach (['last_push_at', 'last_poll_at', 'last_handshake_at'] as $column) {
            $value = ZktecoDevice::query()->max($column);

            if ($value !== null) {
                $stamps[] = CarbonImmutable::parse($value, 'UTC');
            }
        }

        if ($stamps === []) {
            return null;
        }

        return collect($stamps)->sortDesc()->first();
    }

    private function engineStartDate(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            config('attendance.engine_start_date', '2026-10-08'),
            VenueTime::TIMEZONE,
        )->startOfDay();
    }

    private function localDate(CarbonInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant->toDateTime())
            ->setTimezone(VenueTime::TIMEZONE)
            ->startOfDay();
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
