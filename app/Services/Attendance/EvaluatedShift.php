<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The verdict on one shift, before anything is written down.
 *
 * Readonly, and produced by a service that touches no database, so the same
 * input always gives the same answer — which is what makes the simulator
 * trustworthy and what lets a disputed fine be re-derived rather than
 * re-litigated.
 */
final readonly class EvaluatedShift
{
    /**
     * @param  Collection<int, AttendanceLog>  $keptPunches
     * @param  Collection<int, AttendanceLog>  $rawPunches
     * @param  array<int, array{type: string, kind: string, amount: int}>  $fines
     * @param  array<int, string>  $flags
     */
    public function __construct(
        public ExpectedShift $shift,
        public string $outcome,
        public ?CarbonImmutable $clockInAt,
        public ?CarbonImmutable $clockOutAt,
        public ?int $clockInLogId,
        public ?int $clockOutLogId,
        public int $lateMinutes,
        public int $earlyLeaveMinutes,
        public Collection $rawPunches,
        public Collection $keptPunches,
        public array $fines,
        public array $flags,
        public CarbonImmutable $windowStart,
        public CarbonImmutable $windowEnd,
    ) {}

    public function totalFines(): int
    {
        return collect($this->fines)->where('kind', 'fine')->sum('amount');
    }

    public function totalPayDeductions(): int
    {
        return collect($this->fines)->where('kind', 'pay_deduction')->sum('amount');
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    /**
     * Whether two evaluations say the same thing, used by re-evaluation to
     * decide whether a record is worth superseding.
     *
     * Compares the verdict and the money, not the incidental: raw punch counts
     * and flags can shift without changing what anybody owes, and superseding
     * a record over that would churn the history for nothing.
     */
    public function matches(self $other): bool
    {
        return $this->outcome === $other->outcome
            && $this->lateMinutes === $other->lateMinutes
            && $this->earlyLeaveMinutes === $other->earlyLeaveMinutes
            && $this->clockInAt?->getTimestamp() === $other->clockInAt?->getTimestamp()
            && $this->clockOutAt?->getTimestamp() === $other->clockOutAt?->getTimestamp()
            && $this->fineSignature() === $other->fineSignature();
    }

    /**
     * @return array<int, string>
     */
    private function fineSignature(): array
    {
        $signature = collect($this->fines)
            ->map(fn (array $f) => $f['type'].':'.$f['kind'].':'.$f['amount'])
            ->sort()
            ->values()
            ->all();

        return $signature;
    }
}
