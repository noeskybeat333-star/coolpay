<?php

use App\Http\Controllers\StorefrontController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/',
    [StorefrontController::class, 'index']
)->name('store.home');

Route::get(
    '/products/{product:slug}',
    [StorefrontController::class, 'show']
)->name('store.products.show');