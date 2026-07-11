<?php

namespace App\Models;

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

    public function discrepancy()
    {
        return $this->hasOne(TransferDiscrepancy::class);
    }

    public function isPending(): bool
    {
        return $this->outcome === 'pending';
    }
}
