<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * One item's frozen breakdown on one count:
 *
 *   available          = brought_forward + transferred_in + returns_in + other_in
 *   expected_remaining = available - sold_qty - damages_writeoffs - other_out + unrecorded_change
 *   variance_qty       = counted - expected_remaining
 *
 * expected_remaining, counted and variance_qty are copied from the sealed
 * CountSessionItem, the figures any shortage debt is based on.
 * unrecorded_change is the part of that expected figure the movement
 * ledger cannot explain (a stock change written with no direction).
 */
class CountBreakdownLine extends Model
{
    use AppendOnly;

    /** Pop-up figure => the line column its active movements must add up to. */
    public const FIGURE_COLUMNS = [
        'brought_forward' => 'brought_forward',
        'transferred' => 'transferred_in',
        'returns' => 'returns_in',
        'other_in' => 'other_in',
        'sold' => 'sold_qty',
        'damages' => 'damages_writeoffs',
        'other_out' => 'other_out',
        'unrecorded' => 'unrecorded_change',
    ];

    protected $guarded = [];

    protected $casts = [
        'brought_forward' => 'decimal:2',
        'transferred_in' => 'decimal:2',
        'returns_in' => 'decimal:2',
        'other_in' => 'decimal:2',
        'available' => 'decimal:2',
        'sold_qty' => 'decimal:2',
        'sales_amount' => 'decimal:2',
        'damages_writeoffs' => 'decimal:2',
        'other_out' => 'decimal:2',
        'unrecorded_change' => 'decimal:2',
        'expected_remaining' => 'decimal:2',
        'counted' => 'decimal:2',
        'variance_qty' => 'decimal:2',
        'unit_selling_price' => 'decimal:2',
        'unit_cost_price' => 'decimal:2',
        'variance_value_selling' => 'decimal:2',
        'variance_value_cost' => 'decimal:2',
        'has_movement' => 'boolean',
    ];

    public function breakdown()
    {
        return $this->belongsTo(CountBreakdown::class, 'count_breakdown_id');
    }

    public function session()
    {
        return $this->belongsTo(CountSession::class, 'count_session_id');
    }

    public function sessionItem()
    {
        return $this->belongsTo(CountSessionItem::class, 'count_session_item_id');
    }

    public function movements()
    {
        return $this->hasMany(CountBreakdownMovement::class);
    }

    public function notes()
    {
        return $this->hasMany(CountVarianceNote::class);
    }

    public function hasVariance(): bool
    {
        return abs((float) $this->variance_qty) > 0.0001;
    }
}
