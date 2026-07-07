<?php

namespace App\Services;

use App\Exceptions\PinLockedException;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class PinAuthService
{
    public const PIN_LENGTH = 4;

    /**
     * Staff choose their own PIN, so it must not be guessable in one or two
     * tries. Rejects every all-repeated-digit PIN (0000, 1111, ...) and
     * every simple ascending/descending run (1234, 4321, 0123, ...).
     */
    private function trivialPins(): array
    {
        $trivial = [];

        for ($d = 0; $d <= 9; $d++) {
            $trivial[] = str_repeat((string) $d, self::PIN_LENGTH);
        }

        $digits = range(0, 9);
        for ($start = 0; $start <= 6; $start++) {
            $trivial[] = implode('', array_slice($digits, $start, self::PIN_LENGTH));
            $trivial[] = implode('', array_reverse(array_slice($digits, $start, self::PIN_LENGTH)));
        }

        return array_unique($trivial);
    }

    /**
     * Set (or change) a user's PIN. Self-service only — callers must
     * enforce that $user is the authenticated actor, or that this is a
     * manager-initiated force-reset flow explicitly re-setting it.
     *
     * @throws \InvalidArgumentException
     */
    public function setPin(User $user, string $pin): void
    {
        if (!preg_match('/^\d{' . self::PIN_LENGTH . '}$/', $pin)) {
            throw new \InvalidArgumentException('PIN must be exactly ' . self::PIN_LENGTH . ' digits.');
        }

        if (in_array($pin, $this->trivialPins(), true)) {
            throw new \InvalidArgumentException('That PIN is too easy to guess — choose a less predictable one.');
        }

        $lookupHash = $this->lookupHash($pin);

        $taken = User::where('pin_lookup_hash', $lookupHash)->where('id', '!=', $user->id)->exists();

        if ($taken) {
            throw new \InvalidArgumentException('That PIN is already in use by someone else — choose a different one.');
        }

        // These fields are deliberately excluded from $fillable so nothing
        // else in the app can mass-assign them; forceFill is the one
        // trusted path to write them.
        $user->forceFill([
            'pin_hash' => Hash::make($pin),
            'pin_lookup_hash' => $lookupHash,
            'pin_set_at' => now(),
        ])->save();
    }

    /**
     * Manager-initiated reset: clears the PIN entirely so the user must set
     * a brand new one next time — a manager can never view or choose it.
     */
    public function forceReset(User $user, User $resetBy): void
    {
        $user->forceFill([
            'pin_hash' => null,
            'pin_lookup_hash' => null,
            'pin_set_at' => null,
        ])->save();

        activity('pin')
            ->performedOn($user)
            ->causedBy($resetBy)
            ->log("PIN force-reset for {$user->name}");
    }

    /**
     * Resolve which user (if any) just typed this PIN on a kiosk/device
     * number pad. $throttleKey scopes lockout to the device/session making
     * attempts — a wrong PIN doesn't necessarily belong to any user, so
     * lockout can't be tracked per-account.
     *
     * @throws PinLockedException
     */
    public function attempt(string $pin, string $throttleKey): ?User
    {
        if ($this->isLocked($throttleKey)) {
            throw new PinLockedException($this->lockedUntil($throttleKey));
        }

        $user = User::where('pin_lookup_hash', $this->lookupHash($pin))->first();

        if ($user && $user->pin_hash && Hash::check($pin, $user->pin_hash)) {
            $this->clearFailures($throttleKey);

            return $user;
        }

        $this->recordFailure($throttleKey);

        return null;
    }

    public function isLocked(string $throttleKey): bool
    {
        $until = Cache::get("pin_lock:{$throttleKey}");

        return $until !== null && $until > now()->timestamp;
    }

    public function lockedUntil(string $throttleKey): ?int
    {
        return Cache::get("pin_lock:{$throttleKey}");
    }

    private function recordFailure(string $throttleKey): void
    {
        $failuresKey = "pin_failures:{$throttleKey}";
        $failures = (int) Cache::get($failuresKey, 0) + 1;
        Cache::put($failuresKey, $failures, now()->addMinutes(30));

        if ($failures % 5 === 0) {
            $tier = intdiv($failures, 5);
            $seconds = 60 * $tier;
            $until = now()->addSeconds($seconds)->timestamp;

            Cache::put("pin_lock:{$throttleKey}", $until, now()->addSeconds($seconds));

            activity('pin')
                ->withProperties(['throttle_key' => $throttleKey, 'failures' => $failures, 'lockout_seconds' => $seconds])
                ->log('PIN lockout triggered after repeated failed attempts');
        }
    }

    private function clearFailures(string $throttleKey): void
    {
        Cache::forget("pin_failures:{$throttleKey}");
        Cache::forget("pin_lock:{$throttleKey}");
    }

    private function lookupHash(string $pin): string
    {
        return hash_hmac('sha256', $pin, (string) config('app.key'));
    }
}
