<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PwaController;

// PWA Manifest - must be publicly accessible
// Serve PWA files without any auth middleware
Route::get('/manifest.json', function () {
    return response()->file(public_path('manifest.json'), [
        'Content-Type' => 'application/manifest+json',
        'Access-Control-Allow-Origin' => '*',
    ]);
})->withoutMiddleware(['auth', 'web']);

Route::get('/site.webmanifest', function () {
    return response()->file(public_path('site.webmanifest'), [
        'Content-Type' => 'application/manifest+json',
        'Access-Control-Allow-Origin' => '*',
    ]);
})->withoutMiddleware(['auth', 'web']);

Route::get('/sw.js', function () {
    return response()->file(public_path('sw.js'), [
        'Content-Type' => 'application/javascript',
    ]);
})->withoutMiddleware(['auth', 'web']);

Route::get('/browserconfig.xml', function () {
    $content = file_get_contents(public_path('browserconfig.xml'));
    return response($content, 200, [
        'Content-Type' => 'text/xml'
    ]);
})->name('pwa.browserconfig');

Route::get('/offline.html', function () {
    $content = file_get_contents(public_path('offline.html'));
    return response($content, 200, [
        'Content-Type' => 'text/html'
    ]);
})->name('pwa.offline');

/**
 * deploy.sh runs over SSH, which has its own separate OPcache instance
 * from the one PHP-FPM uses to actually serve web requests — restarting
 * PHP-FPM (which most shared hosting doesn't give SSH access to) is the
 * usual fix, but this achieves the same thing by running opcache_reset()
 * from *inside* an actual web-server-served request instead. Token is
 * derived from APP_KEY so this works with zero extra production config,
 * and only opcache_reset() runs — nothing sensitive is exposed.
 */
Route::get('/__ops/reset-opcache/{token}', function (string $token) {
    if (!hash_equals(hash('sha256', config('app.key')), $token)) {
        abort(404);
    }

    if (function_exists('opcache_reset')) {
        opcache_reset();

        return response('opcache reset', 200);
    }

    return response('opcache not enabled', 200);
})->withoutMiddleware(['auth', 'web']);

// Debug/diagnostic routes — never exposed outside local development.
if (app()->environment('local')) {
    Route::get('/cron-test', function () {
        \App\Jobs\CronQueueTestJob::dispatch();
        return 'queued';
    });

    Route::get('/test-manifest', function () {
        return response()->json(['test' => 'manifest working'], 200, [
            'Content-Type' => 'application/json'
        ]);
    });

    Route::get('/manifest-test', function () {
        return file_get_contents(public_path('manifest-test.html'));
    });

    // PWA diagnostic page — shows Service Worker / manifest / install status
    Route::get('/pwa-test', function () {
        return view('pwa-test');
    })->name('pwa.test');
}

// Redirect the homepage directly to the Admin Panel (named 'home' for tests/views)
Route::get('/', function () {
    return redirect('/admin');
})->name('home');

// Provide a named dashboard route used by tests and some views.
Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware('auth')->name('dashboard');

// Provide a lightweight POS route used by views/tests (named `pos.index`).
// This returns a simple redirect to the admin area — the actual POS
// functionality is implemented in Filament; tests only need the route to exist.
Route::get('/pos', function () {
    return redirect('/admin');
})->name('pos.index');

require __DIR__.'/settings.php';

use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\FloorPlanController;

Route::middleware(['auth'])->group(function () {
    Route::post('/stock-transfers', [StockTransferController::class, 'store']);
    Route::post('/stock-transfers/{stockTransfer}/send', [StockTransferController::class, 'send']);
    Route::post('/stock-transfers/{stockTransfer}/receive', [StockTransferController::class, 'receive']);
    Route::post('/stock-transfers/bulk-receive', [StockTransferController::class, 'bulkReceive']);
    Route::get('/warehouses/{warehouse}/product/{product}/quantity', [StockTransferController::class, 'productQuantity']);
    Route::get('/warehouses/{warehouse}/ingredient/{ingredient}/quantity', [StockTransferController::class, 'ingredientQuantity']);

    // Floor plan AJAX routes
    Route::get('/admin/floor-plan/order/{orderId}', [FloorPlanController::class, 'getOrderDetails']);
    Route::get('/admin/floor-plan/popular-items', [FloorPlanController::class, 'getPopularItems']);
    Route::post('/admin/floor-plan/add-item', [FloorPlanController::class, 'addItemToOrder']);
});

use App\Http\Controllers\KioskRegistrationController;

// Kiosk registration — deliberately outside the 'auth' group. The physical
// device isn't registered yet at this point, so there is no user session to
// require; the registration CODE itself is the gate (short-lived, single-use).
Route::get('/kiosk/register', [KioskRegistrationController::class, 'showForm'])->name('kiosk.register');
Route::post('/kiosk/register', [KioskRegistrationController::class, 'register'])
    ->middleware('throttle:10,1')
    ->name('kiosk.register.submit');

// Everything past this point requires a valid, non-revoked device token.
Route::middleware(['kiosk.device'])->group(function () {
    // Idle screen: table grid + PIN pad. No staff identity yet — anyone can
    // see the grid, but nothing about any order, and tapping a table only
    // opens the PIN pad, not an order.
    Route::get('/kiosk', fn () => view('kiosk.idle'))->name('kiosk.home');

    // Reaching an actual order requires both the device token AND a staff_pin
    // identity — this is the only place the reused `pos` component is mounted
    // in the kiosk context.
    Route::middleware(['staff_pin.auth'])->group(function () {
        Route::get('/kiosk/order/{table}', fn ($table) => view('kiosk.order', ['table' => $table]))->name('kiosk.order');
    });
});

use App\Http\Controllers\StaffLoginController;

// Personal-phone equivalent of kiosk registration — one full password login
// establishes trust for this specific device+user, deliberately never
// touching the 'web' guard used by the Filament admin panel.
Route::get('/staff/login', [StaffLoginController::class, 'showForm'])->name('staff.login');
Route::post('/staff/login', [StaffLoginController::class, 'login'])
    // Same named limiter Fortify's own admin login uses (5/min, keyed by
    // email+IP) — this route also validates a real account password
    // (Auth::guard('web')->validate()), so it needs the exact same
    // brute-force protection, not just guard isolation.
    ->middleware('throttle:login')
    ->name('staff.login.submit');

// Same idle-screen/order-wrapper components as the kiosk, reused as-is —
// the only difference is trusted.device (bound to one person) instead of
// kiosk.device (shared), and the PIN is further constrained to that one
// person inside kiosk-idle-screen itself.
Route::middleware(['trusted.device'])->group(function () {
    Route::get('/staff', fn () => view('kiosk.idle'))->name('staff.home');

    Route::middleware(['staff_pin.auth'])->group(function () {
        Route::get('/staff/order/{table}', fn ($table) => view('kiosk.order', ['table' => $table]))->name('staff.order');
    });
});
