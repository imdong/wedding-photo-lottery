<?php

use App\Http\Controllers\Api\AdminAuthenticationController;
use App\Http\Controllers\Api\AdminPhotoReviewController;
use App\Http\Controllers\Api\CompletedPhotoUploadController;
use App\Http\Controllers\Api\CurrentAdminSessionController;
use App\Http\Controllers\Api\CurrentUserController;
use App\Http\Controllers\Api\CurrentUserPhotoController;
use App\Http\Controllers\Api\CurrentUserPhotoStatsController;
use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Controllers\Api\NextAdminReviewController;
use App\Http\Controllers\Api\PhoneAuthenticationController;
use App\Http\Controllers\Api\PreparedPhotoUploadController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthCheckController::class)->name('health.show');
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::post('/login', AdminAuthenticationController::class)
        ->middleware('throttle:admin-login')
        ->name('login');

    Route::middleware('admin.token')->group(function (): void {
        Route::get('/session', CurrentAdminSessionController::class)->name('session.show');
        Route::get('/reviews/next', NextAdminReviewController::class)->name('reviews.next');
        Route::post('/photos/{photo}/review', AdminPhotoReviewController::class)->name('photos.review');
    });
});
Route::post('/auth/phone', PhoneAuthenticationController::class)
    ->middleware('throttle:phone-registration')
    ->name('auth.phone');
Route::get('/me', CurrentUserController::class)
    ->middleware('user.token')
    ->name('me.show');
Route::get('/me/stats', CurrentUserPhotoStatsController::class)
    ->middleware('user.token')
    ->name('me.stats');
Route::post('/uploads/prepare', PreparedPhotoUploadController::class)
    ->middleware('user.token')
    ->name('uploads.prepare');
Route::post('/uploads/{photo}/complete', CompletedPhotoUploadController::class)
    ->middleware('user.token')
    ->name('uploads.complete');
Route::get('/photos/mine', CurrentUserPhotoController::class)
    ->middleware('user.token')
    ->name('photos.mine');
