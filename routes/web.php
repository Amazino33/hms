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

    // Floor plan AJAX routes
    Route::get('/admin/floor-plan/order/{orderId}', [FloorPlanController::class, 'getOrderDetails']);
    Route::get('/admin/floor-plan/popular-items', [FloorPlanController::class, 'getPopularItems']);
    Route::post('/admin/floor-plan/add-item', [FloorPlanController::class, 'addItemToOrder']);
});
