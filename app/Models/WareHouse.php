<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $table = 'warehouses';

    protected $guarded = [];

    protected $casts = [
        'type' => 'string',
    ];
}
