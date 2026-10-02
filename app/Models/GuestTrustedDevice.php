<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A phone that may see a room's bill (D25): it has had at least one request
 * approved by reception for this stay. Append-only — written once, by
 * RoomRequestApprovalService, never changed or deleted.
 */
class GuestTrustedDevice extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Trusted guest devices are append-only.'));
        static::deleting(fn () => throw new \LogicException('Trusted guest devices are append-only.'));
    }

    public function stay()
    {
        return $this->belongsTo(Booking::class, 'stay_id');
    }

    public static function isTrusted(?int $stayId, string $deviceId): bool
    {
        return $stayId !== null && static::where('stay_id', $stayId)->where('device_id', $deviceId)->exists();
    }
}
