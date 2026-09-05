<?php

namespace App\Models;

use App\Services\AnnouncementService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnouncementTarget extends Model
{
    protected $guarded = [];

    /**
     * Targets are eager-loaded into the cached live list and decide who an
     * announcement applies to, so changing them has to drop that cache
     * exactly as editing the announcement itself does.
     */
    protected static function booted(): void
    {
        static::saved(fn () => AnnouncementService::flushLiveCache());
        static::deleted(fn () => AnnouncementService::flushLiveCache());
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }
}
