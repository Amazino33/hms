<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class HandoverDiscrepancyRecount extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'new_quantity' => 'decimal:2',
        'recomputed_variance' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->useLogName('handover_discrepancy_recount')
            ->dontLogEmptyChanges();
    }

    public function discrepancy()
    {
        return $this->belongsTo(HandoverDiscrepancy::class, 'handover_discrepancy_id');
    }

    public function countedBy()
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function witnessedBy()
    {
        return $this->belongsTo(User::class, 'witnessed_by');
    }

    public function orderedBy()
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    public function inventoryTransaction()
    {
        return $this->belongsTo(InventoryTransaction::class);
    }

    public function ingredientTransaction()
    {
        return $this->belongsTo(IngredientTransaction::class);
    }
}
