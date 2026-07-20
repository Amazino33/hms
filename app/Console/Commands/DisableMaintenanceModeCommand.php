<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class DisableMaintenanceModeCommand extends Command
{
    protected $signature = 'hms:maintenance-up';

    protected $description = 'Take the site out of maintenance mode';

    public function handle(): int
    {
        Artisan::call('up');

        $this->components->info('Maintenance mode disabled.');

        return self::SUCCESS;
    }
}
