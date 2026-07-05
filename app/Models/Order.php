<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class Order extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $guarded = [];
    protected $casts = [
        'destination' => 'string',
        'paid_cash' => 'decimal:2',
        'paid_pos' => 'decimal:2',
        'served_at' => 'datetime',
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
}
