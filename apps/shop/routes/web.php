<?php

use App\Http\Controllers\StorefrontController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CartController;

Route::get(
    '/',
    [StorefrontController::class, 'index']
)->name('store.home');

Route::get(
    '/cart',
    [CartController::class, 'index']
)->name('store.cart.index');

Route::post(
    '/cart/items/{product}',
    [CartController::class, 'store']
)->name('store.cart.store');

Route::patch(
    '/cart/items/{product}',
    [CartController::class, 'update']
)->name('store.cart.update');

Route::delete(
    '/cart/items/{product}',
    [CartController::class, 'destroy']
)->name('store.cart.destroy');

Route::get(
    '/products/{product:slug}',
    [StorefrontController::class, 'show']
)->name('store.products.show');