<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

/**
 * One shift a person was scheduled to work.
 *
 * Readonly because Phase 2 matches punches against these: a value that could
 * be edited after it was computed is a value a fine cannot be defended on.
 *
 * shiftDate is the Lagos date of startsAt, always — an overnight shift
 * belongs to the day it began, so "Tuesday night" is Tuesday even though most
 * of it happens on Wednesday.
 */
final readonly class ExpectedShift
{
    public function __construct(
        public int $userId,
        public int $assignmentId,
        public int $templateId,
        public string $shiftDate,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public bool $isHandover,
    ) {}

    public function durationMinutes(): int
    {
        return (int) $this->startsAt->diffInMinutes($this->endsAt);
    }

    /**
     * True when the shift runs past midnight into another calendar day.
     */
    public function crossesMidnight(): bool
    {
        return $this->startsAt->toDateString() !== $this->endsAt->toDateString();
    }
}
