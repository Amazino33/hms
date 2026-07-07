<?php

namespace App\Http\Middleware;

use App\Services\TrustedDeviceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The personal-phone equivalent of EnsureValidKioskDevice — but where a
 * kiosk with no valid device token is simply denied (it was never meant to
 * self-serve registration), a personal phone with no trust yet is a normal,
 * expected first-visit state: send it to a password login that establishes
 * trust, rather than a hard error.
 */
class EnsureTrustedDevice
{
    public const COOKIE_NAME = 'trusted_device_token';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(self::COOKIE_NAME);
        $user = $token ? (new TrustedDeviceService())->resolveToken($token) : null;

        if (!$user) {
            return redirect()->route('staff.login');
        }

        $request->attributes->set('trusted_device_user', $user);
        session(['trusted_device_user_id' => $user->id]);

        return $next($request);
    }
}
