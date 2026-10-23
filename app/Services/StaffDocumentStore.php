<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where applicants' photos and documents go: the private 'local' disk
 * (storage/app/private), never public/. Admin screens show them through
 * short-lived signed URLs (the disk has `serve` on and private visibility).
 *
 * Photos are re-encoded through MenuPhotoProcessor, which applies the
 * phone's rotation and strips EXIF — including the GPS position a phone
 * camera embeds, which for a home-taken selfie is the applicant's address.
 * A PDF (a scanned ID or guarantor form) is kept as uploaded.
 */
class StaffDocumentStore
{
    public const DISK = 'local';

    public const PHOTO_WIDTH = 600;

    public const DOCUMENT_WIDTH = 1600;

    public const MAX_KILOBYTES = 8192;

    /**
     * @throws \Exception with a message written for the applicant
     */
    public function storePhoto(UploadedFile $file, string $directory): string
    {
        return $this->storeImage($file, $directory, 'photo', self::PHOTO_WIDTH);
    }

    /**
     * An ID or guarantor form: a photo of it, or a PDF scan.
     *
     * @throws \Exception with a message written for the applicant
     */
    public function storeDocument(UploadedFile $file, string $directory, string $name): string
    {
        if (strtolower((string) $file->getMimeType()) === 'application/pdf') {
            if ($file->getSize() > self::MAX_KILOBYTES * 1024) {
                throw new \Exception('That PDF is over 8 MB. Take a photo of the page instead.');
            }

            $path = $directory.'/'.$name.'-'.Str::random(8).'.pdf';
            Storage::disk(self::DISK)->put($path, file_get_contents($file->getRealPath()));

            return $path;
        }

        return $this->storeImage($file, $directory, $name, self::DOCUMENT_WIDTH);
    }

    /** @param  array<int, ?string>  $paths */
    public function delete(array $paths): void
    {
        Storage::disk(self::DISK)->delete(array_values(array_filter($paths)));
    }

    private function storeImage(UploadedFile $file, string $directory, string $name, int $width): string
    {
        $processor = new MenuPhotoProcessor;
        $mime = strtolower((string) $file->getMimeType());

        // Checked here so the applicant sees wording about their photo, not
        // the menu-photo wording assertAcceptable() uses. It still runs after,
        // for the iPhone (HEIC) message and the size limit.
        if (! in_array($mime, MenuPhotoProcessor::ACCEPTED_MIME_TYPES, true)
            && ! in_array($mime, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true)) {
            throw new \Exception('Upload a photo (JPG, PNG or WebP) or a PDF.');
        }

        $processor->assertAcceptable($file);

        $path = $directory.'/'.$name.'-'.Str::random(8).'.webp';
        Storage::disk(self::DISK)->put($path, $processor->webpFromPath($file->getRealPath(), $width));

        return $path;
    }
}
