<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class Commission extends Model
{
    use LogsActivity;

    // Only created_at — no updated_at column.
    const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'amount'     => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->useLogName('commission')
            ->dontLogEmptyChanges();
    }

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
