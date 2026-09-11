<?php

namespace App\Models;

use App\Support\VenueTime;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class StockTransfer extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'cancelled_at' => 'datetime',
    ];

    /**
     * Absolute, unambiguous, and identical on every transfer screen.
     * Relative labels ("3 days ago") were all the storekeeper's history
     * ever showed and the bartender's history showed nothing at all, so
     * two people looking at the same transfer had no shared way to say
     * WHEN — which is exactly the thing a stock dispute turns on.
     */
    public const DISPLAY_DATE_FORMAT = VenueTime::DATETIME_FORMAT;

    /**
     * When the storekeeper raised it. Paired everywhere with
     * received_at_label so both halves of a transfer's life read side by
     * side — the gap between them is the part worth noticing.
     */
    public function getSentAtLabelAttribute(): ?string
    {
        return VenueTime::convert($this->created_at)?->format(self::DISPLAY_DATE_FORMAT);
    }

    /**
     * When the LAST line landed — a partially-received transfer has no
     * single receipt moment, and the last one is what closed it out.
     * Null while nothing has been received yet.
     */
    public function getReceivedAtLabelAttribute(): ?string
    {
        $latest = $this->items->pluck('received_at')
            ->concat($this->ingredientItems->pluck('received_at'))
            ->filter()
            ->max();

        return VenueTime::convert($latest)?->format(self::DISPLAY_DATE_FORMAT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('stock_transfer')
            ->dontLogEmptyChanges();
    }

    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function ingredientItems()
    {
        return $this->hasMany(IngredientTransferItem::class);
    }

    public function fromWarehouse()
    {
        return $this->belongsTo(WareHouse::class, 'from_warehouse_id');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(WareHouse::class, 'to_warehouse_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * True once every product and ingredient line has moved past 'pending' —
     * used by receiveTransferLine() to decide whether the transfer should
     * flip to 'received' (fully resolved) vs stay 'partially_received'.
     */
    public function allLinesResolved(): bool
    {
        return $this->items()->where('outcome', 'pending')->doesntExist()
            && $this->ingredientItems()->where('outcome', 'pending')->doesntExist();
    }
}
