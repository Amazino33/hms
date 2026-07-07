<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class CashDrop extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'declared_amount' => 'decimal:2',
        'confirmed_amount' => 'decimal:2',
        'confirmed_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('cash_drop')
            ->dontLogEmptyChanges();
    }

    public function waiter()
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
