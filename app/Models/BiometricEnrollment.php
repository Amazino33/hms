<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A badge as the terminal itself knows it — the machine ID plus the name
 * somebody typed on the device when enrolling that finger.
 *
 * Deliberately separate from User: this is the device's own record, not ours.
 * It is the only name available for a badge that has never been paired to a
 * staff profile, and it stays readable even after a pairing is changed.
 */
class BiometricEnrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'biometric_id',
        'name',
        'privilege',
        'card',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];
}
