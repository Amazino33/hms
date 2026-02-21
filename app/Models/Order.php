<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [
        'destination' => 'string',
        'paid_cash' => 'decimal:2',
        'paid_pos' => 'decimal:2',
    ];

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
