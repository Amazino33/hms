<?php

use App\Http\Controllers\GuestMenuController;
use Illuminate\Support\Facades\Route;

/*
| Guest QR ordering (Phase 2). Loaded by bootstrap/app.php OUTSIDE the
| 'web' group: no session, no CSRF, no cookie encryption (D9). The phone is
| known by the `selum_gd` device cookie (EnsureGuestDevice); writes are
| JSON-only and rate-limited per device with a generous per-IP ceiling
| (D10, limiters in AppServiceProvider).
|
| Every guest link is built by App\Support\GuestUrl from a qr_token —
| never a table id or number. The catch-all place route is LAST, so a
| malformed code still lands on the friendly "no longer valid" page.
*/

Route::get('/menu', [GuestMenuController::class, 'menuOnly'])
    ->middleware('throttle:guest-page')
    ->name('guest.menu');

Route::get('/m/{token}/availability', [GuestMenuController::class, 'availability'])
    ->middleware('throttle:guest-poll')
    ->name('guest.availability');

Route::get('/m/{token}/requests', [GuestMenuController::class, 'index'])
    ->middleware('throttle:guest-poll')
    ->name('guest.requests');

Route::post('/m/{token}/requests', [GuestMenuController::class, 'submit'])
    ->middleware('throttle:guest-submit')
    ->name('guest.requests.submit');

Route::post('/m/{token}/requests/{ref}/cancel', [GuestMenuController::class, 'cancel'])
    ->middleware('throttle:guest-cancel')
    ->name('guest.requests.cancel');

// Phase 4 — live bill, payment claims, waiter calls, another round.
Route::get('/m/{token}/bill', [GuestMenuController::class, 'bill'])
    ->middleware('throttle:guest-poll')
    ->name('guest.bill');

Route::get('/m/{token}/round', [GuestMenuController::class, 'round'])
    ->middleware('throttle:guest-poll')
    ->name('guest.round');

Route::get('/m/{token}/accounts', [GuestMenuController::class, 'accounts'])
    ->middleware('throttle:guest-poll')
    ->name('guest.accounts');

Route::post('/m/{token}/claims', [GuestMenuController::class, 'claim'])
    ->middleware('throttle:guest-claim')
    ->name('guest.claims.store');

Route::post('/m/{token}/claims/{id}/withdraw', [GuestMenuController::class, 'withdrawClaim'])
    ->middleware('throttle:guest-cancel')
    ->name('guest.claims.withdraw');

Route::post('/m/{token}/calls', [GuestMenuController::class, 'call'])
    ->middleware('throttle:guest-call')
    ->name('guest.calls.store');

Route::get('/m/{token}', [GuestMenuController::class, 'place'])
    ->where('token', '.*')
    ->middleware('throttle:guest-page')
    ->name('guest.place');
