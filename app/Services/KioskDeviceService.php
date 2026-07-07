<?php

namespace App\Services;

use App\Models\KioskDevice;
use App\Models\KioskRegistrationCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class KioskDeviceService
{
    private const CODE_LIFETIME_MINUTES = 15;

    /**
     * Generate a one-time registration code. Returned value is the raw code
     * to show the admin once — only its hash is ever persisted.
     */
    public function generateRegistrationCode(User $createdBy): array
    {
        $code = strtoupper(Str::random(8));

        $record = KioskRegistrationCode::create([
            'code_hash' => Hash::make($code),
            'created_by' => $createdBy->id,
            'expires_at' => now()->addMinutes(self::CODE_LIFETIME_MINUTES),
        ]);

        return ['code' => $code, 'record' => $record];
    }

    /**
     * Redeem a registration code on the physical device, creating the
     * KioskDevice and returning the raw device token — this is the only
     * moment the raw token ever exists; only its hash is stored afterwards.
     *
     * @throws \Exception
     */
    public function registerDevice(string $submittedCode, string $deviceName): array
    {
        $candidates = KioskRegistrationCode::query()
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->get();

        $matched = $candidates->first(fn (KioskRegistrationCode $c) => Hash::check($submittedCode, $c->code_hash));

        if (!$matched) {
            throw new \Exception('That registration code is invalid or has expired.');
        }

        return DB::transaction(function () use ($matched, $deviceName) {
            $rawToken = Str::random(64);

            $device = KioskDevice::create([
                'name' => $deviceName,
                'token_hash' => hash('sha256', $rawToken),
                'registered_by' => $matched->created_by,
                'registered_at' => now(),
            ]);

            $matched->update([
                'used_at' => now(),
                'kiosk_device_id' => $device->id,
            ]);

            return ['device' => $device, 'token' => $rawToken];
        });
    }

    /**
     * Resolve a raw device token to its (non-revoked) KioskDevice, touching
     * last_seen_at. Returns null for a revoked or unknown token — callers
     * must treat null as "not registered," full stop.
     */
    public function resolveToken(string $rawToken): ?KioskDevice
    {
        $device = KioskDevice::query()
            ->where('token_hash', hash('sha256', $rawToken))
            ->whereNull('revoked_at')
            ->first();

        if ($device) {
            $device->update(['last_seen_at' => now()]);
        }

        return $device;
    }

    public function revoke(KioskDevice $device, User $revokedBy): void
    {
        $device->update([
            'revoked_at' => now(),
            'revoked_by' => $revokedBy->id,
        ]);
    }

    public function rename(KioskDevice $device, string $newName): void
    {
        $device->update(['name' => $newName]);
    }
}
