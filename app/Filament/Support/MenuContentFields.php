<?php

namespace App\Filament\Support;

use App\Models\GuestItemPairing;
use App\Services\MenuPhotoProcessor;
use App\Support\GuestMenuOptions;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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

    /**
     * Phase 7C: find names typed in ALL CAPS so the owner can tidy them.
     * Nothing is changed automatically. Case-sensitive on MySQL too (its
     * default collation would otherwise call "Beer" and "BEER" equal);
     * names with no letters at all ("33") are not "all caps".
     */
    public static function allCapsNameFilter(): Filter
    {
        return Filter::make('all_caps_name')
            ->label('Name in ALL CAPS')
            ->toggle()
            ->query(fn (Builder $query) => DB::getDriverName() === 'mysql'
                ? $query->whereRaw('CAST(name AS BINARY) = CAST(UPPER(name) AS BINARY)')->whereRaw('CAST(name AS BINARY) <> CAST(LOWER(name) AS BINARY)')
                : $query->whereRaw('name = UPPER(name)')->whereRaw('name <> LOWER(name)'));
    }

    /**
     * Phase 7C (D38): the owner's selling tools for the guest menu — badge,
     * "We recommend", order within the category, and up to 3 "goes well
     * with" items. Honest only: nothing here is computed or faked.
     *
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    public static function guestSelling(): array
    {
        return [
            Select::make('guest_badge')
                ->label('Badge')
                ->options(GuestMenuOptions::BADGES)
                ->placeholder('None'),
            Toggle::make('guest_recommended')
                ->label('Recommended')
                ->helperText('Shown in the "We recommend" row at the top of the guest menu.')
                ->inline(false),
            TextInput::make('guest_sort')
                ->label('Sort order')
                ->numeric()
                ->integer()
                ->helperText('Lower comes first within its category. Empty = after the numbered ones, by name.'),
            Select::make('guest_pairs')
                ->label('Goes well with')
                ->helperText('Up to 3, in order. Suggested to the guest right after they add this item.')
                ->multiple()
                ->searchable()
                ->reorderable()
                ->maxItems(GuestItemPairing::MAX)
                ->options(fn (?Model $record) => collect(GuestMenuOptions::itemOptions())
                    ->except($record ? [GuestMenuOptions::keyFor($record)] : [])
                    ->all())
                ->rules([
                    fn (?Model $record): Closure => function (string $attribute, $value, Closure $fail) use ($record) {
                        $value = (array) $value;

                        if (count($value) > GuestItemPairing::MAX) {
                            $fail('Pick at most '.GuestItemPairing::MAX.' items that go well with this one.');
                        }

                        if ($record && in_array(GuestMenuOptions::keyFor($record), $value, true)) {
                            $fail('An item can\'t go well with itself.');
                        }
                    },
                ])
                ->dehydrated(false)
                ->loadStateFromRelationshipsUsing(fn (Select $component, ?Model $record) => $component->state($record ? GuestItemPairing::keysFor($record) : []))
                ->saveRelationshipsUsing(fn (Model $record, $state) => GuestItemPairing::syncFor($record, (array) $state)),
        ];
    }
}
