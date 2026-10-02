<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * The venue logo's PUBLIC copies for the guest pages, on the 'branding'
 * disk (public/media/branding):
 *
 *   logo.webp         ≤ 400 px — the full logo (Phase 4)
 *   logo-splash.webp  ≤ 480 px — the welcome splash crest (Phase 7A, D32)
 *   logo-mark.webp    ≤  96 px — the header mark, from its own optional
 *                                 upload on Guest Ordering Settings (7A)
 *
 * The uploaded original stays where Company Settings put it — on the
 * private default disk — and is never linked to. logo / logo-splash are
 * written whenever the logo changes (Company saved hook) and by
 * `branding:publish-logo`.
 */
class BrandingLogo
{
    public const DISK = 'branding';

    public const MAX_WIDTH = 400;

    public const SPLASH_WIDTH = 480;

    public const MARK_WIDTH = 96;

    public const LOGO = 'logo.webp';

    public const SPLASH = 'logo-splash.webp';

    public const MARK = 'logo-mark.webp';

    public static function publicPath(string $file = self::LOGO): string
    {
        return public_path('media/branding/'.$file);
    }

    /** The full logo's URL, cache-busted by the file's age; null when there is none. */
    public static function url(): ?string
    {
        return self::urlFor(self::LOGO);
    }

    public static function splashUrl(): ?string
    {
        return self::urlFor(self::SPLASH) ?? self::url();
    }

    /** The header logo: the small mark if one was uploaded, otherwise the full logo. */
    public static function headerUrl(): ?string
    {
        return self::urlFor(self::MARK) ?? self::url();
    }

    public static function hasMark(): bool
    {
        return is_file(self::publicPath(self::MARK));
    }

    private static function urlFor(string $file): ?string
    {
        $path = self::publicPath($file);

        return is_file($path) ? '/media/branding/'.$file.'?v='.filemtime($path) : null;
    }

    /**
     * @return bool whether a public copy now exists
     */
    public static function publish(?Company $company = null): bool
    {
        $company ??= Company::first();
        $source = self::originalPath($company?->logo_path);

        if (! $source) {
            // Logo removed (or unreadable): no public copies either.
            File::delete([self::publicPath(self::LOGO), self::publicPath(self::SPLASH)]);

            return false;
        }

        $processor = new MenuPhotoProcessor;
        File::ensureDirectoryExists(dirname(self::publicPath()));
        File::put(self::publicPath(self::LOGO), $processor->webpFromPath($source, self::MAX_WIDTH));
        File::put(self::publicPath(self::SPLASH), $processor->webpFromPath($source, self::SPLASH_WIDTH));

        return true;
    }

    /**
     * The optional small header mark (e.g. the shield-only crop). Checked
     * and encoded like any menu photo; the upload itself is not kept.
     *
     * @return string the file name on the 'branding' disk
     *
     * @throws \Exception with a message written for the person uploading
     */
    public static function publishMark(UploadedFile $file): string
    {
        $processor = new MenuPhotoProcessor;
        $processor->assertAcceptable($file);

        File::ensureDirectoryExists(dirname(self::publicPath()));
        File::put(self::publicPath(self::MARK), $processor->webpFromPath($file->getRealPath(), self::MARK_WIDTH));

        return self::MARK;
    }

    /** No mark: the header falls back to the full logo. */
    public static function removeMark(): void
    {
        File::delete(self::publicPath(self::MARK));
    }

    /** The original on whichever disk the upload used (default disk first, then 'public'). */
    private static function originalPath(?string $logoPath): ?string
    {
        if (! $logoPath) {
            return null;
        }

        foreach (array_unique([config('filament.default_filesystem_disk', config('filesystems.default')), 'public']) as $disk) {
            if (Storage::disk($disk)->exists($logoPath)) {
                return Storage::disk($disk)->path($logoPath);
            }
        }

        return null;
    }
}
