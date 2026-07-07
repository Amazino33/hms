<?php

namespace App\Http\Middleware;

use App\Services\KioskDeviceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The device token lives in an httpOnly cookie — not localStorage. This is
 * a fixed, physical, single-purpose kiosk device with no need for JS to
 * read the token itself, so httpOnly (unreadable by any client-side script,
 * including an XSS payload) is strictly safer than localStorage here.
 */
class EnsureValidKioskDevice
{
    public const COOKIE_NAME = 'kiosk_device_token';

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(self::COOKIE_NAME);

        $device = $token ? (new KioskDeviceService())->resolveToken($token) : null;

        if (!$device) {
            abort(401, 'This device is not registered, or its registration has been revoked.');
        }

        $request->attributes->set('kiosk_device', $device);

        // Session, not just the request attribute: Livewire's own AJAX
        // update calls are separate HTTP requests, and this needs to survive
        // for the whole kiosk interaction (session persists via cookie;
        // request attributes are this-request-only by design).
        $request->session()->put('kiosk_device_id', $device->id);

        return $next($request);
    }
}
