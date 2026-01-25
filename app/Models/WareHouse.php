<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WareHouse extends Model
{
    protected $table = 'warehouses';

    protected $guarded = [];

    protected $casts = [
        'type' => 'string',
    ];
}
