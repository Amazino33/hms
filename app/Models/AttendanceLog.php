<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'biometric_id',
        'punch_time',
        'punch_state',
        'verify_mode',
    ];

    protected $casts = [
        'punch_time' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The terminal's own record for this badge — where the name typed into
     * the machine lives. Joined on the machine ID rather than a foreign key
     * because the badge, not the staff profile, is what both sides share;
     * rows with no paired user still resolve.
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(BiometricEnrollment::class, 'biometric_id', 'biometric_id');
    }
}
