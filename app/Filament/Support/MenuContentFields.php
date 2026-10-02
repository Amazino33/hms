<?php

namespace App\Filament\Support;

use App\Services\MenuPhotoProcessor;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The guest-menu content fields (Phase 1A), shared by the menu-item and
 * product resources so both edit photos and descriptions identically.
 */
class MenuContentFields
{
    public const DESCRIPTION_MAX = 160;

    public static function photo(): FileUpload
    {
        return FileUpload::make('photo_path')
            ->label('Photo')
            ->image()
            ->disk(MenuPhotoProcessor::DISK)
            ->visibility('public')
            ->acceptedFileTypes(MenuPhotoProcessor::ACCEPTED_MIME_TYPES)
            ->maxSize(MenuPhotoProcessor::MAX_KILOBYTES)
            ->imagePreviewHeight('160')
            ->helperText('JPG, PNG or WebP, up to 8 MB. Saved as a small and a large WebP for the guest menu; the original is not kept.')
            ->validationMessages([
                'mimetypes' => 'Upload a JPG, PNG or WebP photo. '.MenuPhotoProcessor::HEIC_MESSAGE,
                'max' => 'That photo is over 8 MB. Take it at a lower resolution and try again.',
            ])
            // Replaces Filament's own storing: the processor writes the two
            // WebP files and the upload itself is discarded. Only the large
            // path goes into the column; HasMenuPhoto derives the thumb.
            ->saveUploadedFileUsing(function (TemporaryUploadedFile $file, FileUpload $component): string {
                try {
                    return (new MenuPhotoProcessor)->process($file)['photo_path'];
                } catch (\Throwable $e) {
                    throw ValidationException::withMessages([
                        $component->getStatePath() => get_class($e) === \Exception::class
                            ? $e->getMessage()
                            : 'That photo could not be read. Try a different photo, or take it again.',
                    ]);
                }
            })
            // Removing a photo in the form only clears the column; the
            // model deletes the old files once the change is saved.
            ->deleteUploadedFileUsing(fn () => null);
    }

    public static function description(): Textarea
    {
        return Textarea::make('description')
            ->label('Description')
            ->rows(2)
            ->maxLength(self::DESCRIPTION_MAX)
            ->live(debounce: 300)
            ->hint(fn (?string $state) => mb_strlen((string) $state).' / '.self::DESCRIPTION_MAX)
            ->helperText('One short line shown under the item on the guest menu.');
    }

    public static function thumbnailColumn(): ImageColumn
    {
        return ImageColumn::make('photo_thumb_path')
            ->label('Photo')
            ->disk(MenuPhotoProcessor::DISK)
            ->visibility('public')
            ->square()
            ->imageHeight(40);
    }

    public static function missingPhotoFilter(): Filter
    {
        return Filter::make('missing_photo')
            ->label('Missing photo')
            ->toggle()
            ->query(fn (Builder $query) => $query->whereNull('photo_path'));
    }
}
