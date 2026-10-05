<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Order extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $guarded = [];

    /** Still being made or carried to the table. */
    public const IN_PROGRESS = ['pending', 'preparing', 'ready'];

    protected $casts = [
        'destination' => 'string',
        'paid_cash' => 'decimal:2',
        'paid_pos' => 'decimal:2',
        'served_at' => 'datetime',
        'stock_deducted_at' => 'datetime',
    ];

    /**
     * Only the fields that matter for accountability (status transitions,
     * cancellations, returns, payment totals) are logged — order creation
     * is high volume, so we deliberately don't log every attribute on
     * every new order, only these.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'cancellation_reason', 'is_return', 'amount_paid', 'total_amount'])
            ->logOnlyDirty()
            ->useLogName('order')
            ->dontLogEmptyChanges();
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function table()
    {
        return $this->belongsTo(Table::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function kioskDevice()
    {
        return $this->belongsTo(KioskDevice::class);
    }

    public function processedByUser()
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function payments()
    {
        return $this->hasMany(OrderPayment::class);
    }

    public function commission()
    {
        return $this->hasOne(Commission::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function pickedUpBy()
    {
        return $this->belongsTo(User::class, 'picked_up_by');
    }

    /**
     * Distinct from pickedUpBy()/picked_up_at (porter-delivery custody for
     * room orders only) — this is the KDS board's own "collected from the
     * kitchen pass" event, set for any kitchen order.
     */
    public function kdsPickedUpBy()
    {
        return $this->belongsTo(User::class, 'kds_picked_up_by');
    }

    /**
     * Table name for a dine-in order, "Room N" for a room order, else
     * "Takeaway" — the single place that resolves an order's origin so
     * every display/notification collapses to one call instead of
     * repeating the table-vs-room fallback chain everywhere.
     */
    public function getOriginLabelAttribute(): string
    {
        // Deliberately not $this->table — Eloquent's own protected $table
        // property (the DB table name, "orders") shadows the table()
        // relation for direct in-class access; getRelationValue() is what
        // __get() itself delegates to, so it correctly resolves the
        // relation the same way external callers like Blade views do.
        $table = $this->getRelationValue('table');

        if ($table) {
            return $table->name;
        }

        if ($this->booking_id) {
            return 'Room '.($this->booking?->room?->number ?? '?');
        }

        return 'Takeaway';
    }

    /**
     * Orders that mean someone is really at the table: food or drinks
     * still on their way, or a served bill that still owes money. A
     * leftover "served" order worth ₦0 (every item removed) or already
     * settled does not hold the table — it used to keep tables showing
     * "Occupied" on the kiosk long after the guests had gone.
     */
    public function scopeOccupyingTable(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->where('is_return', false)->orWhereNull('is_return'))
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->whereIn('status', self::IN_PROGRESS)->where('total_amount', '>', 0))
                ->orWhere(fn (Builder $q) => $q->where('status', 'served')->whereColumn('amount_paid', '<', 'total_amount')));
    }
}
