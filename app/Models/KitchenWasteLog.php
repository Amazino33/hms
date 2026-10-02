<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Cooked kitchen food cancelled after Mark Ready — a record only, never a
 * stock movement (the ingredients already left at Mark Ready and cooked
 * food is never restocked). Written by OrderObserver; see the migration.
 */
class KitchenWasteLog extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'sale_value' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->useLogName('kitchen_waste')
            ->dontLogEmptyChanges();
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
