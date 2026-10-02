<?php

namespace App\Models\Concerns;

use App\Services\MenuPhotoProcessor;
use Illuminate\Support\Facades\Storage;

/**
 * Menu photo bookkeeping shared by MenuItem and Product (Phase 1A).
 *
 * photo_path always holds the LARGE file MenuPhotoProcessor wrote; the
 * thumb's path is derived from it here, so the two columns can never point
 * at different uploads. When the photo is replaced or removed, the old
 * pair of files is deleted once the new path is saved.
 */
trait HasMenuPhoto
{
    public static function bootHasMenuPhoto(): void
    {
        static::saving(function (self $model) {
            if ($model->isDirty('photo_path')) {
                $model->photo_thumb_path = MenuPhotoProcessor::thumbPathFor($model->photo_path);
            }
        });

        static::updated(function (self $model) {
            $previous = $model->getOriginal('photo_path');

            if ($model->wasChanged('photo_path') && $previous && $previous !== $model->photo_path) {
                (new MenuPhotoProcessor)->delete($previous);
            }
        });

        static::deleted(function (self $model) {
            // A soft-deleted product can be restored with its photo intact.
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            (new MenuPhotoProcessor)->delete($model->photo_path);
        });
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk(MenuPhotoProcessor::DISK)->url($this->photo_path) : null;
    }

    public function photoThumbUrl(): ?string
    {
        return $this->photo_thumb_path ? Storage::disk(MenuPhotoProcessor::DISK)->url($this->photo_thumb_path) : null;
    }
}
