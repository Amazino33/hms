<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class DamageReport extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:2',
        'resolved_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->useLogName('damage_report')
            ->dontLogEmptyChanges();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(WareHouse::class, 'warehouse_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function inventoryTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class);
    }

    public function ingredientTransaction(): BelongsTo
    {
        return $this->belongsTo(IngredientTransaction::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function itemName(): string
    {
        return $this->product_id
            ? ($this->product?->name ?? 'Unknown product')
            : ($this->ingredient?->name ?? 'Unknown ingredient');
    }
}
