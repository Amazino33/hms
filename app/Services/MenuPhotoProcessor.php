<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Turns one uploaded menu photo into the two WebP files the guest menu
 * uses (Phase 1A), on the 'menu_photos' disk:
 *
 *   items/{uuid}-thumb.webp   400px wide — menu lists
 *   items/{uuid}-large.webp  1000px wide — item detail
 *
 * The phone's orientation flag is applied first (Intervention's
 * autoOrientation), then everything else in the file's metadata —
 * location, camera model — is dropped by re-encoding. The upload itself
 * is never kept. A fresh uuid per upload means a re-uploaded photo always
 * has a new URL, so a phone can never show a stale cached copy.
 *
 * HEIC is deliberately not accepted (owner decision 2026-10-01): GD can't
 * read it, and iPhones already send JPEG when picking from Photos.
 */
class MenuPhotoProcessor
{
    public const DISK = 'menu_photos';

    public const MAX_KILOBYTES = 8192;

    public const ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const HEIC_MESSAGE = 'iPhone photo format (HEIC) isn\'t supported. Choose the photo again from Photos (it uploads as JPEG), or set Camera › Formats › Most Compatible.';

    /** [max width, starting quality, target size in bytes] */
    private const VARIANTS = [
        'large' => [1000, 78, 120 * 1024],
        'thumb' => [400, 72, 40 * 1024],
    ];

    /** Never step quality below this chasing a size target — a soft target, not a hard cap. */
    private const MIN_QUALITY = 50;

    /**
     * @param  bool  $withThumb  false for a banner/special that's only ever shown large
     * @return array{photo_path: string, photo_thumb_path: ?string}
     *
     * @throws \Exception with a message written for the person uploading
     */
    public function process(UploadedFile $file, bool $withThumb = true, string $directory = 'items'): array
    {
        $this->assertAcceptable($file);

        $base = $directory.'/'.Str::uuid();
        $image = ImageManager::gd(autoOrientation: true, strip: true)->read($file->getRealPath());
        $variants = $withThumb ? self::VARIANTS : ['large' => self::VARIANTS['large']];

        $paths = ['thumb' => null];

        // Large first, then scale the same image down for the thumb — each
        // step only ever shrinks.
        foreach ($variants as $variant => [$width, $quality, $target]) {
            $path = "{$base}-{$variant}.webp";
            Storage::disk(self::DISK)->put($path, $this->encodeNear($image->scaleDown(width: $width), $quality, $target));
            $paths[$variant] = $path;
        }

        return ['photo_path' => $paths['large'], 'photo_thumb_path' => $paths['thumb']];
    }

    /**
     * One WebP, scaled down to $width, from an image file already on disk —
     * the venue logo's public copy (Phase 4). Same orientation fix and
     * metadata strip as an upload.
     */
    public function webpFromPath(string $absolutePath, int $width): string
    {
        $image = ImageManager::gd(autoOrientation: true, strip: true)->read($absolutePath);

        return $this->encodeNear($image->scaleDown(width: $width), 85, 60 * 1024);
    }

    /** The thumb that belongs to a large path this processor wrote. */
    public static function thumbPathFor(?string $largePath): ?string
    {
        return $largePath ? Str::replaceLast('-large.webp', '-thumb.webp', $largePath) : null;
    }

    /** Removes both files of a photo; a missing file is not an error. */
    public function delete(?string $largePath): void
    {
        if (! $largePath) {
            return;
        }

        Storage::disk(self::DISK)->delete(array_filter([$largePath, self::thumbPathFor($largePath)]));
    }

    public function assertAcceptable(UploadedFile $file): void
    {
        $mime = strtolower((string) $file->getMimeType());

        if (in_array($mime, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true)
            || in_array(strtolower($file->getClientOriginalExtension()), ['heic', 'heif'], true)) {
            throw new \Exception(self::HEIC_MESSAGE);
        }

        if (! in_array($mime, self::ACCEPTED_MIME_TYPES, true)) {
            throw new \Exception('That file isn\'t a photo this menu can use. Upload a JPG, PNG or WebP image.');
        }

        if ($file->getSize() > self::MAX_KILOBYTES * 1024) {
            throw new \Exception('That photo is over 8 MB. Take it again at a lower resolution, or send it to yourself on WhatsApp first (which shrinks it) and upload that.');
        }
    }

    /**
     * Encode at the starting quality, then step down until the file fits
     * its target or quality hits the floor — a busy photo just stays a bit
     * over target rather than turning to mush.
     */
    private function encodeNear(ImageInterface $image, int $quality, int $targetBytes): string
    {
        do {
            $encoded = (string) $image->toWebp(quality: $quality);
            $quality -= 8;
        } while (strlen($encoded) > $targetBytes && $quality >= self::MIN_QUALITY);

        return $encoded;
    }
}
