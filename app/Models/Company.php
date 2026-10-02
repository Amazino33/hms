<?php

namespace App\Models;

use App\Services\BrandingLogo;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $guarded = [];

    /**
     * The venue's name as guests see it (D30) — the one place it comes
     * from: Company Settings, with the app name only as a fallback before
     * any company row exists. Never type the name into a template.
     */
    public static function displayName(): string
    {
        return (string) (static::first()?->name ?: config('app.name'));
    }

    protected static function booted(): void
    {
        // The guest pages show a public WebP copy of the logo; the uploaded
        // original stays private (Phase 4). A bad image never blocks saving
        // the settings — the copy is just left as it was.
        static::saved(function (Company $company) {
            if ($company->wasChanged('logo_path') || ($company->wasRecentlyCreated && $company->logo_path)) {
                try {
                    BrandingLogo::publish($company);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });
    }
}
