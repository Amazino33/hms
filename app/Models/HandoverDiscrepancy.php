<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class HandoverDiscrepancy extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'shortfall_quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'naira_value' => 'decimal:2',
        'resolved_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('handover_discrepancy')
            ->dontLogEmptyChanges();
    }

    public function item()
    {
        return $this->belongsTo(CountSessionItem::class, 'count_session_item_id');
    }

    public function staffDebt()
    {
        return $this->belongsTo(StaffDebt::class);
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function recounts()
    {
        return $this->hasMany(HandoverDiscrepancyRecount::class);
    }

    public function isPendingResolution(): bool
    {
        return $this->status === 'pending_resolution';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending_resolution', 'pending_investigation'], true);
    }

    /**
     * A pending_investigation line is loudly flagged once it's sat longer
     * than 2 days without a manager coming back to it — see
     * HandoverDiscrepancies::table()'s aging filter/badge.
     */
    public function isAgingInvestigation(): bool
    {
        return $this->status === 'pending_investigation'
            && $this->updated_at->lt(now()->subDays(2));
    }
}
