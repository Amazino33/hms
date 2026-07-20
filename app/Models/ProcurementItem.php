<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcurementItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'entered_qty' => 'decimal:2',
        'base_qty' => 'decimal:2',
        'line_total_cost' => 'decimal:2',
        'unit_cost' => 'decimal:4',
    ];

    public function procurement()
    {
        return $this->belongsTo(Procurement::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function inventoryTransaction()
    {
        return $this->belongsTo(InventoryTransaction::class);
    }
}
