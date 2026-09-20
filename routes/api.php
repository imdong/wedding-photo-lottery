<?php

use App\Http\Controllers\Api\CurrentUserController;
use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Controllers\Api\PhoneAuthenticationController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthCheckController::class)->name('health.show');
Route::post('/auth/phone', PhoneAuthenticationController::class)
    ->middleware('throttle:phone-registration')
    ->name('auth.phone');
Route::get('/me', CurrentUserController::class)
    ->middleware('user.token')
    ->name('me.show');
