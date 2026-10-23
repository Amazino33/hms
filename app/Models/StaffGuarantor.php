<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who vouches for a staff member. Written at approval from the
 * application; a later phase lets existing staff be given one.
 */
class StaffGuarantor extends Model
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(StaffApplication::class, 'staff_application_id');
    }
}
