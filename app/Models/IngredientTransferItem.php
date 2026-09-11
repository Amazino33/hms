<?php

namespace App\Models;

use App\Support\VenueTime;
use Illuminate\Database\Eloquent\Model;

class IngredientTransferItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'entered_qty' => 'decimal:2',
        'units_per_purchase_unit_snapshot' => 'integer',
        'received_quantity' => 'decimal:2',
        'received_at' => 'datetime',
    ];

    public function transfer()
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * The custodian shift this stock actually landed in — the thing that
     * makes a receipt reconcilable against the count that follows it,
     * rather than just "it appeared in the warehouse at some point".
     * Null for receipts taken by a storekeeper/admin, who hold no shift.
     */
    public function receivedShift()
    {
        return $this->belongsTo(Shift::class, 'received_shift_id');
    }

    public function discrepancy()
    {
        return $this->hasOne(TransferDiscrepancy::class);
    }

    public function isPending(): bool
    {
        return $this->outcome === 'pending';
    }

    /**
     * This line's own receipt moment, in the one format every transfer
     * screen uses. Per line rather than per transfer because a partial
     * receipt genuinely happens at several different times, sometimes
     * across two different shifts.
     */
    public function getReceivedAtLabelAttribute(): ?string
    {
        return VenueTime::convert($this->received_at)?->format(StockTransfer::DISPLAY_DATE_FORMAT);
    }
}
