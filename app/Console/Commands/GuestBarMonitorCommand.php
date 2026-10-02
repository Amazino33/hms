<?php

namespace App\Console\Commands;

use App\Services\Guest\GuestBarMonitor;
use Illuminate\Console\Command;

/**
 * Watches the bar for guest drinks stuck without a bartender shift, and for
 * overlapping bartender shifts (Phase 3). Scheduled every minute.
 */
class GuestBarMonitorCommand extends Command
{
    protected $signature = 'guest:bar-monitor';

    protected $description = 'Alert managers about guest drinks waiting with no bartender shift, or overlapping bartender shifts';

    public function handle(GuestBarMonitor $monitor): int
    {
        $result = $monitor->run();

        $this->info("{$result['waiting']} guest drink line(s) waiting; {$result['bartenders']} bartender shift(s) open.");

        return self::SUCCESS;
    }
}
