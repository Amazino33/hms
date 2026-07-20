<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * The single place that actually flips maintenance mode on — both
 * deploy.sh and ManageCompanySettings' "Enable Maintenance Mode" action
 * call this, so the bypass secret and the resources/views/errors/503.blade.php
 * countdown always agree on what the company record currently says,
 * regardless of which of the two triggered it.
 */
class EnableMaintenanceModeCommand extends Command
{
    protected $signature = 'hms:maintenance-down';

    protected $description = 'Put the site into maintenance mode using the company\'s configured message/duration/secret';

    public function handle(): int
    {
        $company = Company::firstOrCreate(['id' => 1], ['name' => config('app.name', 'My Company')]);

        // A stable secret survives across deploys so the same bypass URL
        // keeps working — only generated once, never rotated automatically,
        // since rotating it under a deploy in progress would lock out
        // whoever already has the old one bookmarked.
        if (empty($company->maintenance_secret)) {
            $company->maintenance_secret = Str::random(32);
        }

        $company->maintenance_started_at = now();
        $company->save();

        Artisan::call('down', [
            '--secret' => $company->maintenance_secret,
            '--retry' => 60,
        ]);

        $this->components->info('Maintenance mode enabled.');
        $this->components->info('Bypass URL: ' . rtrim(config('app.url'), '/') . '/' . $company->maintenance_secret);

        return self::SUCCESS;
    }
}
