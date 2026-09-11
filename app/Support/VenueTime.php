<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Every timestamp in this system is STORED in UTC (config('app.timezone')
 * is 'UTC', so now() writes true UTC regardless of what the server's own
 * clock is set to) and, until now, was DISPLAYED in UTC too — nothing
 * converted it on the way to the screen. Lagos is UTC+1, so every date and
 * time on every screen read one hour early, and anything that happened in
 * the first hour of a Lagos day showed under the previous date.
 *
 * The fix is display-only, deliberately. The stored data was never wrong,
 * so there is nothing to migrate and no risk of shifting good rows: the
 * moment a screen converts on the way out, the whole history reads
 * correctly too. Storage stays UTC, which is also what keeps date
 * comparisons in queries honest — a Lagos-local Carbon handed to a
 * where() would bind Lagos wall-clock against UTC-stored rows and quietly
 * select the wrong hour.
 *
 * ->venueTime() is idempotent: setTimezone() on a value already in Lagos
 * is a no-op, so applying it to something already converted (a BusinessDay
 * boundary, say) cannot double-shift it.
 */
class VenueTime
{
    /**
     * The venue's wall-clock zone. Africa/Lagos is a fixed UTC+1 with no
     * DST, so this offset never varies by season — the same reason
     * BusinessDay can treat its 9am boundary as a fixed hour.
     */
    public const TIMEZONE = BusinessDay::TIMEZONE;

    /**
     * The one datetime format the whole app shows staff, so no two screens
     * quote the same moment differently.
     */
    public const DATETIME_FORMAT = 'd M Y, g:i A';

    public const DATE_FORMAT = 'd M Y';

    public const TIME_FORMAT = 'g:i A';

    /**
     * Registers ->venueTime() on both Carbon and CarbonImmutable. The app
     * runs Date::use(CarbonImmutable::class), so model attributes are
     * immutable instances, but plain Carbon still turns up from parsing
     * and from third-party packages — both need the macro or call sites
     * would have to care which one they were holding.
     */
    public static function registerMacros(): void
    {
        $macro = function () {
            /** @var CarbonInterface $this */
            return $this->setTimezone(VenueTime::TIMEZONE);
        };

        Carbon::macro('venueTime', $macro);
        CarbonImmutable::macro('venueTime', $macro);
    }

    /**
     * Convert any instant to venue wall-clock time. Accepts null so call
     * sites formatting a nullable column (received_at, ended_at, settled_at)
     * do not each need their own guard.
     */
    public static function convert(?DateTimeInterface $instant): ?CarbonImmutable
    {
        return $instant
            ? CarbonImmutable::instance($instant)->setTimezone(self::TIMEZONE)
            : null;
    }

    /**
     * Format an instant in venue time, or return $placeholder when there
     * is nothing to show.
     */
    public static function format(?DateTimeInterface $instant, string $format = self::DATETIME_FORMAT, string $placeholder = '—'): string
    {
        return self::convert($instant)?->format($format) ?? $placeholder;
    }
}
