<?php

namespace App\Services\Orders;

use App\Support\VenueTime;
use Illuminate\Support\Facades\DB;

/**
 * The only place an order number is made (Phase 0A).
 *
 *   ORD-{Ymd}-{0001}-{station}   e.g. ORD-20261001-0042-B
 *   RET-{Ymd}-{0001}-{station}   return tickets, their own sequence
 *
 * The date is the Lagos CALENDAR date (not the 9am BusinessDay), and the
 * sequence restarts at 0001 each day, per station. Past 9999 it simply
 * grows to five digits — str_pad never truncates.
 *
 * The counter row is locked for the rest of the caller's transaction, so
 * two orders can never be handed the same number; if the caller's
 * transaction rolls back, the increment rolls back with it and the
 * number is reused rather than skipped.
 */
class OrderNumberGenerator
{
    /**
     * @param  string  $station  the suffix, "B" (bar), "K" (kitchen) or "M" (main)
     */
    public static function next(string $station): string
    {
        return self::issue('ORD', $station, $station);
    }

    /**
     * Return tickets keep their visible RET- prefix and count on a separate
     * sequence, so they never consume (or skip) an ORD- number.
     */
    public static function nextReturn(string $station): string
    {
        return self::issue('RET', $station, "RET-{$station}");
    }

    /**
     * The station letter OrderSplitter has always used: the first letter of
     * the destination, upper-cased ("bar" -> "B").
     */
    public static function stationFor(string $destination): string
    {
        return strtoupper(substr($destination, 0, 1));
    }

    private static function issue(string $prefix, string $station, string $sequenceKey): string
    {
        $date = now()->setTimezone(VenueTime::TIMEZONE);

        $value = DB::transaction(function () use ($date, $sequenceKey) {
            $day = $date->toDateString();

            // insertOrIgnore rather than firstOrCreate: on the first order of
            // a day, two requests can both find no row and both try to
            // insert it — firstOrCreate would let the second one fail on the
            // unique (date, station) index. This way exactly one insert wins
            // and both go on to the same locked row below.
            DB::table('order_number_sequences')->insertOrIgnore([
                'date' => $day,
                'station' => $sequenceKey,
                'last_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('order_number_sequences')
                ->where('date', $day)
                ->where('station', $sequenceKey)
                ->lockForUpdate()
                ->first();

            $next = (int) $row->last_value + 1;

            DB::table('order_number_sequences')
                ->where('id', $row->id)
                ->update(['last_value' => $next, 'updated_at' => now()]);

            return $next;
        });

        return sprintf('%s-%s-%s-%s', $prefix, $date->format('Ymd'), str_pad((string) $value, 4, '0', STR_PAD_LEFT), $station);
    }
}
