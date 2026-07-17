<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The venue's trading day for owner/CEO reporting: closes at 4am WAT
 * (Africa/Lagos, fixed UTC+1, no DST) rather than midnight, so a 1am sale
 * still belongs to "last night" — the standard hospitality night-audit
 * convention. Shifts here are unscheduled (no fixed clock handover time),
 * so this hour is a deliberate convention, not derived from shift data.
 *
 * This differs from the app's configured UTC server timezone and from
 * the plain calendar-day boundaries every pre-existing CEO report used
 * before this module. Every date boundary in owner/CEO reporting must go
 * through here — never Carbon::today()/yesterday() directly — or the
 * same business day will disagree with itself between the snapshot and
 * the live dashboard.
 */
class BusinessDay
{
    public const TIMEZONE = 'Africa/Lagos';

    public const CLOSE_HOUR = 4;

    /**
     * Which business day a given instant belongs to, as a Y-m-d string.
     */
    public static function labelFor(CarbonImmutable|\DateTimeInterface $instant): string
    {
        $local = CarbonImmutable::instance($instant)->setTimezone(self::TIMEZONE);

        return ($local->hour < self::CLOSE_HOUR ? $local->subDay() : $local)->toDateString();
    }

    /**
     * The [start, end) UTC instant range covering the given business day
     * label — start is that date's 4am WAT, end is the next day's.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function boundsFor(string $businessDate): array
    {
        $start = CarbonImmutable::parse($businessDate, self::TIMEZONE)->setTime(self::CLOSE_HOUR, 0)->setTimezone('UTC');

        return [$start, $start->addDay()];
    }

    public static function today(): string
    {
        return self::labelFor(CarbonImmutable::now());
    }

    public static function yesterday(): string
    {
        return CarbonImmutable::parse(self::today())->subDay()->toDateString();
    }

    public static function isToday(string $businessDate): bool
    {
        return $businessDate === self::today();
    }
}
