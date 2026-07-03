<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffDebtRepayment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function staffDebt(): BelongsTo
    {
        return $this->belongsTo(StaffDebt::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
