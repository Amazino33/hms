<?php

namespace App\Services;

use App\Exceptions\PinLockedException;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class PinAuthService
{
    public const PIN_LENGTH = 4;

    /**
     * Rejected PIN choices allowed per person per hour. Each rejection of an
     * in-use PIN confirms that some colleague holds it, so repeated tries are
     * a way of hunting for working PINs: the third locks PIN changes for an
     * hour and tells the super admins.
     */
    public const PIN_SET_MAX_REJECTIONS = 3;

    public const PIN_SET_LOCK_SECONDS = 3600;

    private const PIN_SET_LIMITER_PREFIX = 'pin-set-rejections:';

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
        $limiterKey = self::PIN_SET_LIMITER_PREFIX.$user->id;

        if (RateLimiter::tooManyAttempts($limiterKey, self::PIN_SET_MAX_REJECTIONS)) {
            $minutes = (int) ceil(RateLimiter::availableIn($limiterKey) / 60);

            throw new \InvalidArgumentException("Too many attempts — try again in {$minutes} minute(s).");
        }

        if (!preg_match('/^\d{' . self::PIN_LENGTH . '}$/', $pin)) {
            $this->rejectPinChoice($user, $limiterKey, 'PIN must be exactly ' . self::PIN_LENGTH . ' digits.');
        }

        if (in_array($pin, $this->trivialPins(), true)) {
            $this->rejectPinChoice($user, $limiterKey, 'That PIN is too easy to guess — choose a less predictable one.');
        }

        $lookupHash = $this->lookupHash($pin);

        $taken = User::where('pin_lookup_hash', $lookupHash)->where('id', '!=', $user->id)->exists();

        // Deliberately the same wording a person sees for any unusable PIN
        // that is not a format or guessability problem: saying "someone else
        // has it" handed out a working PIN for another member of staff, since
        // attempt() resolves a person from the PIN alone on a shared kiosk.
        if ($taken) {
            $this->rejectPinChoice($user, $limiterKey, "This PIN can't be used. Please choose a different one.");
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
        // Resetting a super admin's or CEO's PIN is part of taking over
        // their account, so it needs assign_staff_roles like editing them.
        StaffAccessGuard::assertCanManage($resetBy, $user);

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

        // A deactivated person's PIN behaves exactly like a wrong one — no
        // distinct message, and it counts towards the lockout. Every PIN
        // surface comes through here (kiosk, KDS, handover seals, guest
        // credit approval, the guest orders strip), so this one check covers
        // them all.
        if ($user && $user->pin_hash && ! $user->isDeactivated() && Hash::check($pin, $user->pin_hash)) {
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

    /**
     * Count a rejected PIN choice; the third within the hour locks PIN
     * changes and notifies the super admins.
     *
     * @throws \InvalidArgumentException always, with $message
     */
    private function rejectPinChoice(User $user, string $limiterKey, string $message): never
    {
        RateLimiter::hit($limiterKey, self::PIN_SET_LOCK_SECONDS);

        if (RateLimiter::attempts($limiterKey) === self::PIN_SET_MAX_REJECTIONS) {
            // whereHas, not role(): role() throws when the role does not exist.
            $superAdmins = User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->active()->get();

            if ($superAdmins->isNotEmpty()) {
                Notification::make()
                    ->warning()
                    ->title("Repeated PIN rejections for {$user->name}")
                    ->body('PIN changes are locked for this person for one hour.')
                    ->sendToDatabase($superAdmins);
            }

            activity('pin')
                ->performedOn($user)
                ->withProperties(['rejections' => self::PIN_SET_MAX_REJECTIONS])
                ->log("PIN changes locked for {$user->name} after repeated rejections");
        }

        throw new \InvalidArgumentException($message);
    }

    private function lookupHash(string $pin): string
    {
        return hash_hmac('sha256', $pin, (string) config('app.key'));
    }
}
