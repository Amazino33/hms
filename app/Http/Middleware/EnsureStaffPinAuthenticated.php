<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates every kiosk/staff-operational route on the staff_pin guard only.
 * This is the enforcement half of the PIN scope guarantee — even if a
 * request somehow carries a valid 'web' session (e.g. an admin browsing
 * from the same browser), that is not sufficient here; only a staff_pin
 * session opens these routes, and conversely a staff_pin session is never
 * sufficient for Filament panel routes, which check 'web' exclusively.
 */
class EnsureStaffPinAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::guard('staff_pin')->check()) {
            // The kiosk PIN-entry route is registered in a later increment;
            // until then (and for any request that simply has no PIN
            // session), deny outright rather than guessing a redirect target.
            abort(401, 'PIN authentication required.');
        }

        // Everything downstream — including the reused `pos` Livewire
        // component's plain auth()->user()/auth()->id() calls — should
        // transparently resolve against this staff's PIN identity for the
        // rest of this request, without that component needing to know
        // it's running in a kiosk context at all.
        Auth::shouldUse('staff_pin');

        return $next($request);
    }
}
