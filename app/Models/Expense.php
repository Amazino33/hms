<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class Expense extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'date_incurred' => 'date',
        'voided_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->useLogName('expense')
            ->dontLogEmptyChanges();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * The future reporting layer (out of scope here) sums this scope, not
     * the raw table — a voided row stays visible everywhere else, it just
     * never counts toward a total again.
     */
    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }
}
