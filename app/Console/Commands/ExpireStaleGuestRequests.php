<?php

namespace App\Console\Commands;

use App\Services\Guest\GuestRequestService;
use Illuminate\Console\Command;

/**
 * D14: pending guest requests older than 3 hours expire; table sessions with
 * nothing live and no activity for 3 hours close. Scheduled every 10 min.
 */
class ExpireStaleGuestRequests extends Command
{
    protected $signature = 'guest:expire-stale';

    protected $description = 'Expire 3-hour-old pending guest requests and close idle guest table sessions';

    public function handle(GuestRequestService $requests): int
    {
        $result = $requests->expireStale();

        $this->info("Expired {$result['expired']} request(s); closed {$result['closed']} idle session(s); expired {$result['calls']} waiter call(s).");

        return self::SUCCESS;
    }
}
