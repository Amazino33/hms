<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FridgeRestockMark extends Model
{
    protected $guarded = [];

    protected $casts = [
        'marked_quantity' => 'decimal:2',
        'marked_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(WareHouse::class);
    }

    public function markedBy()
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
