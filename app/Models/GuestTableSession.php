<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One sitting at a table (Phase 2). Opens on the first submitted guest
 * request (D14). Closes only through TableCloseService: by the waiter
 * (D20), or after 3 idle hours with nothing live and nothing unpaid
 * (guest:expire-stale). At most one open per table — enforced by
 * GuestRequestService under a row lock AND a unique index.
 *
 * bill_from_at (D19) is where the guest's live bill starts.
 */
class GuestTableSession extends Model
{
    public const CLOSE_AUTO_STALE = 'auto_stale';

    public const CLOSE_STAFF = 'staff';

    public const CLOSE_BY_WAITER = 'closed_by_waiter';

    protected $guarded = [];

    protected $casts = [
        'opened_at' => 'datetime',
        'bill_from_at' => 'datetime',
        'closed_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function table()
    {
        return $this->belongsTo(Table::class);
    }

    public function requests()
    {
        return $this->hasMany(GuestRequest::class);
    }

    public function assignedWaiter()
    {
        return $this->belongsTo(User::class, 'assigned_waiter_user_id');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function handovers()
    {
        return $this->hasMany(GuestSessionHandover::class);
    }

    public function claims()
    {
        return $this->hasMany(GuestPaymentClaim::class);
    }

    public function waiterCalls()
    {
        return $this->hasMany(GuestWaiterCall::class);
    }

    public function moves()
    {
        return $this->hasMany(TableMove::class);
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('closed_at');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /** D19 — rows from before Phase 4 fall back to when the sitting opened. */
    public function billFrom(): \Carbon\CarbonInterface
    {
        return $this->bill_from_at ?? $this->opened_at;
    }
}
