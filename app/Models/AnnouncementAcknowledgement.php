<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A signature. Created once by AnnouncementService::acknowledge() and
 * never modified afterwards — nothing in the app updates or deletes one.
 */
class AnnouncementAcknowledgement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
