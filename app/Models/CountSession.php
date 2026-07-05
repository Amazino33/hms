<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class CountSession extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'opened_at' => 'datetime',
        'confirmed_by_outgoing_at' => 'datetime',
        'confirmed_by_incoming_at' => 'datetime',
        'submitted_for_review_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('count_session')
            ->dontLogEmptyChanges();
    }

    public function warehouse()
    {
        return $this->belongsTo(WareHouse::class);
    }

    public function items()
    {
        return $this->hasMany(CountSessionItem::class);
    }

    public function openedBy()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function outgoingUser()
    {
        return $this->belongsTo(User::class, 'outgoing_user_id');
    }

    public function incomingUser()
    {
        return $this->belongsTo(User::class, 'incoming_user_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isHandover(): bool
    {
        return in_array($this->type, ['bar_handover', 'kitchen_handover'], true);
    }

    public function isDraft(): bool
    {
        return $this->status === 'counting';
    }

    public function isPendingReview(): bool
    {
        return $this->status === 'pending_review';
    }

    public function isReviewed(): bool
    {
        return $this->status === 'reviewed';
    }

    /**
     * Who is presumed accountable for a shortfall found in this session —
     * the outgoing custodian for a handover, or whoever opened a stocktake.
     */
    public function accountableUserId(): ?int
    {
        return $this->outgoing_user_id ?? $this->opened_by;
    }
}
