<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PwaController;

// PWA Manifest - must be publicly accessible
Route::get('/site.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');

// PWA Test Page
Route::get('/pwa-test', function () {
    return view('pwa-test');
})->name('pwa.test');

// PWA Service Worker and static files
Route::get('/sw.js', function () {
    $content = file_get_contents(public_path('sw.js'));
    return response($content, 200, [
        'Content-Type' => 'application/javascript',
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Service-Worker-Allowed' => '/'
    ]);
})->name('pwa.service-worker');

Route::get('/debug-login', function () {
    // 1. Force Login as your user (ID 2 based on your previous output)
    $user = \App\Models\User::find(2); 
    auth()->login($user);

    // 2. Check the "Panel Access" Gate specifically
    // This is the gate that throws the 403 error on the dashboard
    $canAccessPanel = $user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin'));

    return [
        'User Email' => $user->email,
        'User Role' => $user->getRoleNames(),
        'Is Logged In?' => auth()->check() ? 'YES' : 'NO',
        'Can Access Panel?' => $canAccessPanel ? 'YES' : 'NO (This is the problem!)',
        'Environment' => app()->environment(),
    ];
});

Route::get('/test-403', function() {
    return 'This works!';
});

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

// Debug route to test manifest
Route::get('/test-manifest', function () {
    return response()->json(['test' => 'manifest working'], 200, [
        'Content-Type' => 'application/json'
    ]);
});

// Debug route for manifest test page
Route::get('/manifest-test', function () {
    return file_get_contents(public_path('manifest-test.html'));
});

// Redirect the homepage directly to the Admin Panel
Route::get('/', function () {
    return redirect('/admin');
});

require __DIR__.'/settings.php';

use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\FloorPlanController;

Route::middleware(['auth'])->group(function () {
    Route::post('/stock-transfers', [StockTransferController::class, 'store']);
    Route::post('/stock-transfers/{stockTransfer}/send', [StockTransferController::class, 'send']);
    Route::post('/stock-transfers/{stockTransfer}/receive', [StockTransferController::class, 'receive']);
    Route::get('/warehouses/{warehouse}/product/{product}/quantity', [StockTransferController::class, 'productQuantity']);

    // Floor plan AJAX routes
    Route::get('/admin/floor-plan/order/{orderId}', [FloorPlanController::class, 'getOrderDetails']);
    Route::get('/admin/floor-plan/popular-items', [FloorPlanController::class, 'getPopularItems']);
    Route::post('/admin/floor-plan/add-item', [FloorPlanController::class, 'addItemToOrder']);
});
