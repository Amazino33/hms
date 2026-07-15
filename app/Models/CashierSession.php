<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * The custody chain's third link (waiter -> cashier -> supervisor). What
 * she accrues is never stored as a running balance — always derived live
 * from Shift.cashier_counted_cash and CashDrop.confirmed_amount rows in
 * her own name, within [opened_at, declared_at ?? now()] — see
 * CashierSessionService::accruedCash(). That avoids exactly the kind of
 * drift-from-a-stored-total bug a running balance invites.
 */
class CashierSession extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'opened_at' => 'datetime',
        'declared_at' => 'datetime',
        'declared_closing_cash' => 'decimal:2',
        'closed_at' => 'datetime',
        'supervisor_counted_cash' => 'decimal:2',
        'gap' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('cashier_session')
            ->dontLogEmptyChanges();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function outflows()
    {
        return $this->hasMany(CashierSessionOutflow::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
