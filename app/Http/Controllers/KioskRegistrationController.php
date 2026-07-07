<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureValidKioskDevice;
use App\Services\KioskDeviceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class KioskRegistrationController extends Controller
{
    public function showForm()
    {
        return view('kiosk.register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'device_name' => 'required|string|max:100',
            'code' => 'required|string',
        ]);

        try {
            $result = (new KioskDeviceService())->registerDevice($data['code'], $data['device_name']);
        } catch (\Exception $e) {
            return back()->withErrors(['code' => $e->getMessage()])->withInput();
        }

        // 10-year cookie: this is a fixed physical device meant to stay
        // registered indefinitely until an admin explicitly revokes it.
        // httpOnly + secure: unreadable by client-side JS, sent only over
        // HTTPS in production.
        $cookie = Cookie::make(
            EnsureValidKioskDevice::COOKIE_NAME,
            $result['token'],
            60 * 24 * 365 * 10,
            path: '/',
            secure: app()->environment('production'),
            httpOnly: true,
            sameSite: 'lax',
        );

        return redirect()->route('kiosk.home')->withCookie($cookie);
    }
}
