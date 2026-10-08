<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * An order line placed but not yet marked ready when the count locked,
 * frozen so a variance caused by drinks or dishes still in progress can be
 * told apart from missing stock.
 */
class CountOpenOrder extends Model
{
    use AppendOnly;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:2',
        'placed_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(CountSession::class, 'count_session_id');
    }
}
