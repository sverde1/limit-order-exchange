<?php

use App\Http\Controllers\Api\OrdersController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\TradesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/profile/add-demo-assets', [ProfileController::class, 'addDemoAssets']);
    Route::get('/profile/orders', [ProfileController::class, 'orders']);
    Route::get('/trades', [TradesController::class, 'index']);

    Route::get('/orders', [OrdersController::class, 'index']);
    Route::post('/orders', [OrdersController::class, 'create']);
    Route::post('/orders/{order}/cancel', [OrdersController::class, 'cancel']);
    // Route for testing Pusher events, development only
    // Route::get('/orders/test-pusher', [OrdersController::class, 'pusherTest']);
});
