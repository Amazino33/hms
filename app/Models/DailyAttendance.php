<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyAttendance extends Model
{
    protected $table = 'daily_attendances';

    // Views don't have true timestamps, disable them
    public $timestamps = false;

    // Treat it as read-only (unguarded doesn't matter since we won't insert)
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'first_punch' => 'datetime',
        'last_punch' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
