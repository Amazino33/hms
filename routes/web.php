<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PwaController;

// PWA Manifest - must be publicly accessible
Route::get('/site.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');

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
