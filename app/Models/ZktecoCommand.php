<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZktecoCommand extends Model
{
    protected $fillable = [
        'serial',
        'command',
        'sent_at',
        'return_code',
        'responded_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'responded_at' => 'datetime',
    ];
}
