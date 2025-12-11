<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('trade/limit-order', function () {
    return Inertia::render('trade/LimitOrder');
})->middleware(['auth', 'verified'])->name('trade.limit-order');

Route::get('trade/overview', function () {
    return Inertia::render('trade/OrdersOverview');
})->middleware(['auth', 'verified'])->name('trade.overview');

require __DIR__.'/settings.php';
