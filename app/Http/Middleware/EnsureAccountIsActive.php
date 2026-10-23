<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends any session that belongs to a deactivated account, on its next
 * request — admin panel, CEO panel, settings pages, the kiosk and personal
 * phones alike. Login checks stop a leaver getting in; this stops someone
 * already signed in when they were deactivated (or whose notice period
 * just ran out) from carrying on.
 *
 * Runs on the 'web' group (which also carries every Livewire request) and
 * in both Filament panels' own middleware stacks, after the session has
 * started. Checks both guards: 'web' and the kiosk's 'staff_pin'.
 */
class EnsureAccountIsActive
{
    public const MESSAGE = 'This account is no longer active.';

    public function handle(Request $request, Closure $next): Response
    {
        foreach (['web', 'staff_pin'] as $guard) {
            $user = Auth::guard($guard)->user();

            if ($user instanceof User && $user->isDeactivated()) {
                return $this->signOut($request, $guard);
            }
        }

        return $next($request);
    }

    private function signOut(Request $request, string $guard): Response
    {
        // Worked out before the session is cleared.
        $target = $this->loginUrlFor($request, $guard);

        Auth::guard($guard)->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Both forms, into the fresh session: the Fortify login page reads
        // 'status'; Filament's login page and the kiosk show notifications.
        $request->session()->flash('status', self::MESSAGE);
        Notification::make()->danger()->title(self::MESSAGE)->persistent()->send();

        return redirect()->to($target);
    }

    private function loginUrlFor(Request $request, string $guard): string
    {
        if ($guard === 'staff_pin') {
            return $request->is('kiosk', 'kiosk/*', 'kds', 'kds/*')
                ? route('kiosk.home')
                : route('staff.login');
        }

        if ($request->is('ceo', 'ceo/*')) {
            return route('filament.ceo.auth.login');
        }

        if ($request->is('settings', 'settings/*', 'dashboard')) {
            return route('login');
        }

        return route('filament.admin.auth.login');
    }
}
