<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;

/**
 * A same-day reservation with no deposit is released once the configured
 * hour passes, freeing the room for a walk-in. Deposit-backed reservations
 * are explicitly exempt — a guest who paid to hold the room keeps it.
 * Scheduled hourly (routes/console.php); safe to run more than once a day
 * since it only ever acts on rows still in 'reserved' status.
 */
class AutoReleaseReservations extends Command
{
    protected $signature = 'hms:auto-release-reservations';

    protected $description = 'Release today\'s no-deposit reservations to no_show once the configured hour has passed';

    public function handle(): int
    {
        $releaseHour = config('hms.reservation_auto_release_hour', 18);

        if (now()->hour < $releaseHour) {
            $this->info("Not yet {$releaseHour}:00 — nothing to release.");

            return self::SUCCESS;
        }

        $today = now()->toDateString();

        $released = Booking::where('status', 'reserved')
            ->where('check_in', $today)
            ->where(fn ($q) => $q->whereNull('deposit')->orWhere('deposit', '<=', 0))
            ->get();

        foreach ($released as $booking) {
            $booking->update(['status' => 'no_show']);

            activity('booking')
                ->performedOn($booking)
                ->withProperties(['reason' => 'auto_release', 'release_hour' => $releaseHour])
                ->log('Reservation auto-released (no deposit, past release hour)');
        }

        $this->info("Auto-released {$released->count()} no-deposit reservation(s).");

        return self::SUCCESS;
    }
}
