<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureTrustedDevice;
use App\Services\TrustedDeviceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * Deliberately separate from Fortify's own login pipeline (used for the
 * Filament admin panel) — this only ever verifies credentials to establish
 * device trust for the staff operational surface. It never starts a 'web'
 * session, so there is no path from here into the admin panel.
 */
class StaffLoginController extends Controller
{
    public function showForm()
    {
        return view('staff.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::guard('web')->validate($credentials)) {
            return back()->withErrors(['email' => 'Those credentials are incorrect.'])->withInput();
        }

        $user = \App\Models\User::where('email', $credentials['email'])->firstOrFail();

        ['token' => $token] = (new TrustedDeviceService())->trust($user);

        $cookie = Cookie::make(
            EnsureTrustedDevice::COOKIE_NAME,
            $token,
            60 * 24 * 365 * 2,
            path: '/',
            secure: app()->environment('production'),
            httpOnly: true,
            sameSite: 'lax',
        );

        return redirect()->route('staff.home')->withCookie($cookie);
    }
}
