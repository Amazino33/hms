<?php

namespace App\Console\Commands;

use App\Services\BrandingLogo;
use Illuminate\Console\Command;

/**
 * Writes the public guest-page copy of the venue logo
 * (public/media/branding/logo.webp) from the private original. Safe to run
 * any number of times; deploy.sh runs it on every deploy.
 */
class PublishBrandingLogo extends Command
{
    protected $signature = 'branding:publish-logo';

    protected $description = 'Write the public WebP copy of the company logo for the guest pages';

    public function handle(): int
    {
        try {
            $published = BrandingLogo::publish();
        } catch (\Throwable $e) {
            $this->warn('Could not convert the logo: '.$e->getMessage());

            return self::SUCCESS;
        }

        $this->info($published ? 'Logo published to public/media/branding/logo.webp.' : 'No company logo set — nothing published.');

        return self::SUCCESS;
    }
}
