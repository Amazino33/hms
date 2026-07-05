<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CountSessionSubCount extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    public function item()
    {
        return $this->belongsTo(CountSessionItem::class, 'count_session_item_id');
    }
}
