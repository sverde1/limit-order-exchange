<?php

use App\Http\Controllers\Api\OrdersController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/profile/reset-wallet', [ProfileController::class, 'resetWallet']);

    Route::get('/orders', [OrdersController::class, 'index']);
    Route::post('/orders', [OrdersController::class, 'create']);
    Route::post('/orders/{order}/cancel', [OrdersController::class, 'cancel']);
});
