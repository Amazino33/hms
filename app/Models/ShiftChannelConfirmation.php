<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class ShiftChannelConfirmation extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'expected_amount' => 'decimal:2',
        'confirmed_amount' => 'decimal:2',
        'confirmed_at' => 'datetime',
        'flagged' => 'boolean',
        'ruled_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('shift_channel_confirmation')
            ->dontLogEmptyChanges();
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function ruledBy()
    {
        return $this->belongsTo(User::class, 'ruled_by');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
