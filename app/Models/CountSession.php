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

    public function witnessUser()
    {
        return $this->belongsTo(User::class, 'witness_user_id');
    }

    public function isHandover(): bool
    {
        return in_array($this->type, ['bar_handover', 'kitchen_handover'], true);
    }

    /**
     * A closing count is still a handover-type session (same dual
     * confirmation requirement, same warehouse/role), but the "incoming"
     * person is a witness, not a successor — the outgoing custodian's
     * shift ends and no new shift starts from it.
     */
    public function isClosing(): bool
    {
        return (bool) $this->is_closing;
    }

    /**
     * The peer-to-peer declare/review/dispute/dual-seal flow only applies
     * to a real handover — someone is actually taking over the bar/kitchen.
     * A closing count (no successor) and a main_store_stocktake (solo,
     * manager-reviewed) both stay on the older counting -> pending_review
     * -> reviewed path untouched by this.
     */
    public function isHandoverWithSuccessor(): bool
    {
        return $this->isHandover() && !$this->isClosing();
    }

    /**
     * Set only when the outgoing custodian was absent — the incoming
     * person counted alone and a witness (any PIN holder) co-signs in
     * their place at seal time instead of the outgoing confirming.
     * outgoing_user_id is still recorded on these sessions so
     * accountableUserId() still resolves to the absent bartender/chef.
     */
    public function isUnwitnessed(): bool
    {
        return $this->witness_user_id !== null;
    }

    public function isDraft(): bool
    {
        return $this->status === 'counting';
    }

    public function isDeclared(): bool
    {
        return $this->status === 'declared';
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
