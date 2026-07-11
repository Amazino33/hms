<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class TransferDiscrepancy extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'missing_base_qty' => 'decimal:2',
        'resolved_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('transfer_discrepancy')
            ->dontLogEmptyChanges();
    }

    public function stockTransferItem()
    {
        return $this->belongsTo(StockTransferItem::class);
    }

    public function ingredientTransferItem()
    {
        return $this->belongsTo(IngredientTransferItem::class);
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function reversalInventoryTransaction()
    {
        return $this->belongsTo(InventoryTransaction::class, 'reversal_inventory_transaction_id');
    }

    public function reversalIngredientTransaction()
    {
        return $this->belongsTo(IngredientTransaction::class, 'reversal_ingredient_transaction_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * The product or ingredient this discrepancy is about, whichever side
     * it came from — mirrors CountSessionItem::itemName()'s product/
     * ingredient split.
     */
    public function itemName(): string
    {
        if ($this->stockTransferItem) {
            return $this->stockTransferItem->product?->name ?? '—';
        }

        return $this->ingredientTransferItem?->ingredient?->name ?? '—';
    }
}
