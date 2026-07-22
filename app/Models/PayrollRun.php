<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One row per pay period. draft (recomputable) -> sealed (money columns on
 * every line frozen) -> closed (every line acknowledged or closed-with-
 * reason). voided is terminal — a correction never edits a sealed run, it
 * voids it and creates a new draft with supersedes_id pointing back at it,
 * mirroring DailyBusinessSnapshot.
 */
class PayrollRun extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'payday' => 'date',
        'sealed_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('payroll_run')
            ->dontLogEmptyChanges();
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSealed(): bool
    {
        return $this->status === 'sealed';
    }
}
