<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IngredientTransferItem extends Model
{
    protected $guarded = [];

    public function transfer()
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
