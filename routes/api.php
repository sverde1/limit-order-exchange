<?php

use App\Http\Controllers\Api\OrdersController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'throttle:api'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'show']);

    Route::get('/orders', [OrdersController::class, 'index']);
    Route::post('/orders', [OrdersController::class, 'store']);
    Route::post('/orders/{order}/cancel', [OrdersController::class, 'cancel']);
});
