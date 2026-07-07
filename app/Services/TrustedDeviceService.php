<?php

namespace App\Services;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Str;

class TrustedDeviceService
{
    /**
     * Establishes trust after a real password verification — returns the
     * raw token to store client-side once; only its hash is persisted.
     */
    public function trust(User $user): array
    {
        $rawToken = Str::random(64);

        $device = TrustedDevice::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'trusted_at' => now(),
        ]);

        return ['device' => $device, 'token' => $rawToken];
    }

    /**
     * Resolve a raw trusted-device token to its (non-revoked) owning user,
     * touching last_seen_at. Revoked or unknown tokens resolve to null.
     */
    public function resolveToken(string $rawToken): ?User
    {
        $device = TrustedDevice::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->whereNull('revoked_at')
            ->first();

        if (!$device) {
            return null;
        }

        $device->update(['last_seen_at' => now()]);

        return $device->user;
    }

    public function revoke(TrustedDevice $device): void
    {
        $device->update(['revoked_at' => now()]);
    }
}
