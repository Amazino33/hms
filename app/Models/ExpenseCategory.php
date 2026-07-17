<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class ExpenseCategory extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('expense_category')
            ->dontLogEmptyChanges();
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
