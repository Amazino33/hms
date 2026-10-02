<?php

namespace App\Models\Concerns;

use App\Services\Guest\QrTokens;
use App\Support\GuestUrl;

/**
 * Gives every new table and room its guest QR token the moment it's
 * created (Phase 1B). Existing rows were given one by the migration.
 */
trait HasQrToken
{
    public static function bootHasQrToken(): void
    {
        static::creating(function (self $model) {
            if (blank($model->qr_token)) {
                $model->qr_token = QrTokens::generate();
            }
        });
    }

    /** The URL this place's QR code points at. */
    public function guestUrl(): ?string
    {
        return $this->qr_token ? GuestUrl::forToken($this->qr_token) : null;
    }
}
