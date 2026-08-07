<?php

use App\Http\Controllers\StorefrontController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;

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
    '/checkout',
    [CheckoutController::class, 'create']
)->name('store.checkout.create');

Route::post(
    '/checkout',
    [CheckoutController::class, 'store']
)
    ->middleware('throttle:5,1')
    ->name('store.checkout.store');

Route::get(
    '/checkout/success',
    [CheckoutController::class, 'success']
)->name('store.checkout.success');

Route::get(
    '/products/{product:slug}',
    [StorefrontController::class, 'show']
)->name('store.products.show');