<?php

namespace App\Console\Commands;

use App\Services\StaffDeactivationService;
use Illuminate\Console\Command;

/**
 * Finishes off deactivations whose notice period has run out.
 *
 * A future-dated deactivation records the date and nothing else, because
 * the person is still working. Once left_at passes, every login and PIN
 * check already refuses them (User::isDeactivated()); this clears what is
 * left behind — the kiosk PIN, which otherwise stays set and keeps that
 * PIN reserved, trusted phones and any open sessions. Safe to run at any
 * time: it only touches people who are already deactivated and still hold
 * one of those.
 */
class FinaliseDeactivations extends Command
{
    protected $signature = 'hms:finalise-deactivations';

    protected $description = 'Clear PINs, trusted phones and sessions of staff whose leaving date has passed';

    public function handle(StaffDeactivationService $service): int
    {
        $count = $service->finaliseDue();

        $this->info("Finalised {$count} deactivation(s).");

        return self::SUCCESS;
    }
}
