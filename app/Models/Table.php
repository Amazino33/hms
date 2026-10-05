<?php

namespace App\Models;

use App\Models\Concerns\HasQrToken;
use Illuminate\Database\Eloquent\Model;

class Table extends Model
{
    use HasQrToken;

    protected $guarded = [];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * The latest order that really holds this table (Order::occupyingTable)
     * — used to show "Occupied" and who's handling the table on the kiosk
     * grid, since the order itself already carries the attributed waiter.
     */
    public function latestActiveOrder()
    {
        return $this->hasOne(Order::class)->ofMany(['id' => 'max'], fn ($q) => $q->occupyingTable());
    }

    /**
     * What the table grid shows: Occupied from live orders (never from the
     * stored flag alone, which could be left behind), otherwise Reserved /
     * Cleaning as set by staff, otherwise Available.
     */
    public function displayStatus(): string
    {
        if ($this->latestActiveOrder) {
            return 'occupied';
        }

        return in_array($this->status, ['reserved', 'cleaning'], true) ? $this->status : 'available';
    }
}
