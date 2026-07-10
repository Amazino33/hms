<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class CountSessionItemReview extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'incoming_quantities' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('count_session_item_review')
            ->dontLogEmptyChanges();
    }

    public function item()
    {
        return $this->belongsTo(CountSessionItem::class, 'count_session_item_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isAccepted(): bool
    {
        return $this->outcome === 'accepted';
    }

    public function isDisputed(): bool
    {
        return $this->outcome === 'disputed';
    }

    public function isUnresolved(): bool
    {
        return $this->outcome === 'unresolved';
    }
}
