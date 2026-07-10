<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class CountSessionSubCount extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    /**
     * Every write here during the counting/declared phases is a real
     * amendment worth a full before/after trail — most importantly the
     * outgoing custodian correcting their own declared figure during
     * dispute resolution (CountSessionService::amendDeclaration()), which
     * has no other audit record of its own.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('count_session_sub_count')
            ->dontLogEmptyChanges();
    }

    public function item()
    {
        return $this->belongsTo(CountSessionItem::class, 'count_session_item_id');
    }
}
