<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guest pages are stateless (D9): no Laravel session, no CSRF token. A
 * phone is recognised by one random device cookie instead — `selum_gd`,
 * 32 characters, httpOnly, Secure, SameSite=Lax, 30 days. SameSite=Lax is
 * what keeps another site from posting requests as this phone.
 *
 * The cookie is plain (the guest group has no EncryptCookies middleware):
 * it carries no meaning beyond "the same phone as before".
 */
class EnsureGuestDevice
{
    public const COOKIE = 'selum_gd';

    public const ATTRIBUTE = 'guest_device_id';

    public function handle(Request $request, Closure $next): Response
    {
        $deviceId = (string) $request->cookies->get(self::COOKIE, '');
        $isNew = ! preg_match('/^[A-Za-z0-9]{32}$/', $deviceId);

        if ($isNew) {
            $deviceId = Str::random(32);
        }

        $request->attributes->set(self::ATTRIBUTE, $deviceId);

        $response = $next($request);

        if ($isNew) {
            $response->headers->setCookie(new Cookie(
                self::COOKIE,
                $deviceId,
                now()->addDays(30),
                '/',
                null,
                true,  // Secure
                true,  // httpOnly
                false,
                Cookie::SAMESITE_LAX,
            ));
        }

        return $response;
    }
}
