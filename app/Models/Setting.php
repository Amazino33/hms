<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A minimal key/value settings store — no such mechanism existed anywhere
 * in the app before this (confirmed by audit); everything runtime-
 * adjustable previously required a .env/config change and a deploy.
 * Read through SettingsService, not this model directly, so reads stay
 * cached and writes stay activity-logged consistently.
 *
 * Deliberately no LogsActivity trait here — SettingsService::set() already
 * logs every change explicitly, with the causer passed in directly rather
 * than auto-detected from auth() (this is called from console/service
 * contexts with no authenticated request, where auto-detection silently
 * logs a null causer). Adding the trait too just produced two log rows
 * per change, one correct and one not.
 */
class Setting extends Model
{
    protected $guarded = [];

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
