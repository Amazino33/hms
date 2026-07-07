<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Table extends Model
{
    protected $guarded = [];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Whoever most recently placed a still-active (not yet paid/cancelled)
     * order at this table — used to show "who's handling this table" on
     * the kiosk/staff table grid without a separate active-shift lookup,
     * since the order itself already carries the attributed waiter.
     */
    public function latestActiveOrder()
    {
        return $this->hasOne(Order::class)
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->latestOfMany();
    }
}
