<?php

namespace App\Services\Ceo;

use Carbon\CarbonImmutable;

/**
 * A closed, inclusive [start, end] calendar-day range. Both ends are
 * start-of-day Carbon instances — callers needing a query boundary use
 * startBoundary()/endBoundary() to get the actual inclusive-through-
 * midnight timestamp bounds.
 */
class DateRange
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {
    }

    public function days(): int
    {
        return $this->start->startOfDay()->diffInDays($this->end->startOfDay()) + 1;
    }

    public function startBoundary(): CarbonImmutable
    {
        return $this->start->startOfDay();
    }

    public function endBoundary(): CarbonImmutable
    {
        return $this->end->endOfDay();
    }

    public function eachDate(): array
    {
        $dates = [];
        $cursor = $this->start->startOfDay();
        $end = $this->end->startOfDay();

        while ($cursor->lte($end)) {
            $dates[] = $cursor;
            $cursor = $cursor->addDay();
        }

        return $dates;
    }
}
