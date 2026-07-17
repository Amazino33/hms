<?php

namespace App\Services\Ceo;

use App\Support\BusinessDay;
use Carbon\CarbonImmutable;

/**
 * A closed, inclusive [start, end] range of business-day labels (see
 * BusinessDay — a business day runs 4am WAT to 4am WAT, not midnight to
 * midnight). start/end are themselves just date labels; callers needing
 * an actual query boundary use startBoundary()/endBoundary(), which
 * resolve through BusinessDay so every report reads the same instants
 * for the same labeled day.
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
        return BusinessDay::boundsFor($this->start->toDateString())[0];
    }

    /**
     * Inclusive upper bound (the instant one second before the range's
     * last business day closes).
     */
    public function endBoundary(): CarbonImmutable
    {
        return BusinessDay::boundsFor($this->end->toDateString())[1]->subSecond();
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
