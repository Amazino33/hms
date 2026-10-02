<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A guest's WhatsApp number, saved by reception from the order chat (D27).
 * One row per stay + phone. Append-only, except that "Agreed to receive
 * specials" may be turned ON later (never off here) — logged by whoever
 * does it. Only opted-in rows are ever exported for marketing.
 */
class GuestContact extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'marketing_opt_in' => 'boolean',
        'opted_in_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (GuestContact $contact) {
            $turningOn = $contact->getOriginal('marketing_opt_in') == false
                && $contact->marketing_opt_in === true
                && array_diff(array_keys($contact->getDirty()), ['marketing_opt_in', 'opted_in_at']) === [];

            if (! $turningOn) {
                throw new \LogicException('A guest contact only changes by turning specials ON.');
            }
        });

        static::deleting(fn () => throw new \LogicException('Guest contacts are append-only.'));
    }

    public function stay()
    {
        return $this->belongsTo(Booking::class, 'stay_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** Turns specials on, once, and logs who did it. */
    public function optIn(User $by): void
    {
        if ($this->marketing_opt_in) {
            return;
        }

        $this->update(['marketing_opt_in' => true, 'opted_in_at' => now()]);

        activity('guest_contact')->performedOn($this)->causedBy($by)->log('Guest agreed to receive specials');
    }
}
