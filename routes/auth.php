<?php

use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// Super Admin — a single, shop-agnostic entry point (no {shop} in the URL).
Route::middleware('guest')->prefix('superadmin')->name('superadmin.')->group(function () {
    Volt::route('login', 'pages.auth.superadmin-login')->name('login');
    Volt::route('forgot-password', 'pages.auth.forgot-password')->name('password.request');
    Volt::route('reset-password/{token}', 'pages.auth.reset-password')->name('password.reset');
});

// Shop-scoped — every shop gets its own auth URLs at /{shop-slug}/...,
// resolved via the same Route::bind('shop', ...) as the /s/{shop}/* tenant
// routes (see AppServiceProvider::boot()). pages.auth.login checks the
// authenticated user actually belongs to this shop (Super Admins excepted).
Route::middleware('guest')->prefix('{shop}')->group(function () {
    Volt::route('register', 'pages.auth.register')->name('register');
    Volt::route('login', 'pages.auth.login')->name('login');
    Volt::route('forgot-password', 'pages.auth.forgot-password')->name('password.request');
    Volt::route('reset-password/{token}', 'pages.auth.reset-password')->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Volt::route('verify-email', 'pages.auth.verify-email')
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Volt::route('confirm-password', 'pages.auth.confirm-password')
        ->name('password.confirm');
});
