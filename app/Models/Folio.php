<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * One folio per booking, created at reservation time (not check-in) so a
 * deposit has somewhere to post immediately. The running balance is always
 * computed live from lines() — never stored — since lines are immutable
 * and corrections are appended, not edited.
 */
class Folio extends Model
{
    use LogsActivity;

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->useLogName('folio')
            ->dontLogEmptyChanges();
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function lines()
    {
        return $this->hasMany(FolioLine::class)->orderBy('created_at')->orderBy('id');
    }

    public function balance(): float
    {
        return (float) $this->lines()->sum('amount');
    }
}
