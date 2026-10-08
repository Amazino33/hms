<?php

namespace App\Models\Concerns;

use App\Exceptions\AppendOnlyViolation;

/**
 * Rows are written once and never changed through the model: updating or
 * deleting one throws. Only created_at is kept, since nothing is ever
 * updated. A cascade from a deleted parent (the data-reset command) still
 * works, because that happens in the database, not through this model.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function ($model) {
            throw new AppendOnlyViolation(class_basename($model).' rows are append-only and cannot be edited.');
        });

        static::deleting(function ($model) {
            throw new AppendOnlyViolation(class_basename($model).' rows are append-only and cannot be deleted.');
        });
    }

    public function getUpdatedAtColumn()
    {
        return null;
    }
}
