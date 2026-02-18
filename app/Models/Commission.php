<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    // Only created_at — no updated_at column.
    const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'amount'     => 'decimal:2',
        'created_at' => 'datetime',
    ];

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    /** The waiter who earned this commission. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The order that triggered this commission. */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
