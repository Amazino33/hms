<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * One record behind one figure on a breakdown line, with every name and
 * time copied in. Only status 'active' rows count toward the figure;
 * 'voided' (a cancelled sale) and 'info' (context, such as a stock change
 * whose direction was never recorded) are shown but not summed.
 */
class CountBreakdownMovement extends Model
{
    use AppendOnly;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:2',
        'amount' => 'decimal:2',
        'placed_at' => 'datetime',
        'ready_at' => 'datetime',
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
        'recorded_at' => 'datetime',
        'voided_at' => 'datetime',
        'meta' => 'array',
    ];

    public function line()
    {
        return $this->belongsTo(CountBreakdownLine::class, 'count_breakdown_line_id');
    }
}
