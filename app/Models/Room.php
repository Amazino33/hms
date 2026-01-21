<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $guarded = [];

    // Helper to check if room is free
    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }
}
