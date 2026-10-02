<?php

namespace App\Services\Guest;

use App\Models\BarShiftConflictLog;
use App\Models\BarShiftWaitLog;
use App\Models\GuestRequestItem;
use App\Models\User;
use Filament\Notifications\Notification;

/**
 * Run every minute by `guest:bar-monitor` (Phase 3, design §10 and D1).
 *
 *  - Guest drinks waiting while NO bartender shift is open: open a wait log;
 *    after 5 minutes, alert managers in /admin ONCE; close the log the
 *    moment a bartender shift opens (or nothing is waiting any more). The
 *    logs are the owner's record of late bar starts.
 *  - MORE THAN ONE bartender shift open: open a conflict log and alert
 *    managers ONCE; resolve it when the overlap ends.
 *
 * The only code that writes or closes these logs.
 */
class GuestBarMonitor
{
    public const ALERT_AFTER_MINUTES = 5;

    /** @return array{waiting: int, bartenders: int} */
    public function run(): array
    {
        $waiting = GuestRequestItem::whereIn('status', GuestRequestItem::WAITING)->count();
        $shifts = GuestShifts::activeBartenderShifts();

        $this->watchWaiting($waiting, $shifts->count());
        $this->watchConflict($shifts);

        return ['waiting' => $waiting, 'bartenders' => $shifts->count()];
    }

    private function watchWaiting(int $waiting, int $bartenders): void
    {
        $open = BarShiftWaitLog::open()->latest('id')->first();

        if ($waiting === 0 || $bartenders > 0) {
            $open?->update(['ended_at' => now()]);

            return;
        }

        if (! $open) {
            BarShiftWaitLog::create(['started_at' => now(), 'max_waiting_lines' => $waiting]);

            return;
        }

        $changes = [];

        if ($waiting > $open->max_waiting_lines) {
            $changes['max_waiting_lines'] = $waiting;
        }

        if (! $open->alerted_manager_at && $open->started_at->lte(now()->subMinutes(self::ALERT_AFTER_MINUTES))) {
            $this->alertManagers(
                "{$waiting} guest drink ".($waiting === 1 ? 'order' : 'orders').' waiting — no bartender shift started',
                'Guests have been waiting '.$open->started_at->diffForHumans(now(), true).'. Someone needs to start the bartender shift to mark them ready.',
            );
            $changes['alerted_manager_at'] = now();
        }

        if ($changes) {
            $open->update($changes);
        }
    }

    private function watchConflict($shifts): void
    {
        $open = BarShiftConflictLog::open()->latest('id')->first();

        if ($shifts->count() <= 1) {
            $open?->update(['resolved_at' => now()]);

            return;
        }

        if ($open) {
            return;
        }

        BarShiftConflictLog::create(['detected_at' => now(), 'shift_ids' => $shifts->pluck('id')->values()->all()]);

        $this->alertManagers(
            $shifts->count().' bartender shifts open — end the old one',
            'Open now: '.$shifts->map(fn ($s) => $s->user?->name)->filter()->join(', ').'. Guest drinks can only be marked ready once the overlap is cleared, or by choosing who is marking them ready.',
        );
    }

    private function alertManagers(string $title, string $body): void
    {
        User::whereHas('roles', fn ($q) => $q->whereIn('name', ['manager', 'admin', 'super_admin']))
            ->get()
            ->each(fn (User $manager) => Notification::make()->title($title)->body($body)->warning()->sendToDatabase($manager));
    }
}
