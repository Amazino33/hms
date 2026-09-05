<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per person who was required to read an announcement, frozen at
 * publish time. See the create_announcement_recipients_table migration
 * for why this is a stored snapshot rather than a live role lookup.
 */
class AnnouncementRecipient extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_late_join' => 'boolean',
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
