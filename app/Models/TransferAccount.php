<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A venue bank account shown on a guest's bill for transfer payments
 * (Phase 1B). Switched off, never deleted.
 */
class TransferAccount extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('transfer_account')
            ->dontLogEmptyChanges();
    }

    public function scopeActive($query)
    {
        return $query->where('active', true)->orderBy('sort_order')->orderBy('id');
    }
}
