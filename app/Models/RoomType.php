<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class RoomType extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'price_per_night' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('room_type')
            ->dontLogEmptyChanges();
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
