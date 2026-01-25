<?php

use Illuminate\Support\Facades\Route;

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
