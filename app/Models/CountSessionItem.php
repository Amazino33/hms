<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class CountSessionItem extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'expected_quantity_at_open' => 'decimal:2',
        'counted_quantity' => 'decimal:2',
        'adjusted_expected_quantity' => 'decimal:2',
        'variance' => 'decimal:2',
        'unit_selling_price' => 'decimal:2',
        'variance_value' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('count_session_item')
            ->dontLogEmptyChanges();
    }

    public function session()
    {
        return $this->belongsTo(CountSession::class, 'count_session_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function subCounts()
    {
        return $this->hasMany(CountSessionSubCount::class);
    }

    public function review()
    {
        return $this->hasOne(CountSessionItemReview::class);
    }

    public function discrepancy()
    {
        return $this->hasOne(HandoverDiscrepancy::class);
    }

    public function itemName(): string
    {
        return $this->item_type === 'product'
            ? ($this->product?->name ?? '—')
            : ($this->ingredient?->name ?? '—');
    }
}
