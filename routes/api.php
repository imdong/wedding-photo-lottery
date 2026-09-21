<?php

use App\Http\Controllers\Api\AdminAuthenticationController;
use App\Http\Controllers\Api\AdminDrawRoundController;
use App\Http\Controllers\Api\AdminPhotoReviewController;
use App\Http\Controllers\Api\CompletedPhotoUploadController;
use App\Http\Controllers\Api\CurrentAdminSessionController;
use App\Http\Controllers\Api\CurrentUserController;
use App\Http\Controllers\Api\CurrentUserPhotoController;
use App\Http\Controllers\Api\CurrentUserPhotoStatsController;
use App\Http\Controllers\Api\DrawRoundController;
use App\Http\Controllers\Api\DrawWinnerController;
use App\Http\Controllers\Api\GalleryPhotoController;
use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Controllers\Api\NextAdminReviewController;
use App\Http\Controllers\Api\PhoneAuthenticationController;
use App\Http\Controllers\Api\PhotoLikeController;
use App\Http\Controllers\Api\PhotoUnlikeController;
use App\Http\Controllers\Api\PreparedPhotoUploadController;
use App\Http\Controllers\Api\UndoAdminReviewController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthCheckController::class)->name('health.show');
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::post('/login', AdminAuthenticationController::class)
        ->middleware('throttle:admin-login')
        ->name('login');

    Route::middleware('admin.token')->group(function (): void {
        Route::get('/session', CurrentAdminSessionController::class)->name('session.show');
        Route::get('/reviews/next', NextAdminReviewController::class)->name('reviews.next');
        Route::post('/reviews/undo', UndoAdminReviewController::class)->name('reviews.undo');
        Route::post('/photos/{photo}/review', AdminPhotoReviewController::class)->name('photos.review');
        Route::post('/draw-rounds', AdminDrawRoundController::class)->name('draw-rounds.store');
    });
});
Route::post('/auth/phone', PhoneAuthenticationController::class)
    ->middleware('throttle:phone-registration')
    ->name('auth.phone');
Route::post('/draw-rounds/{drawRound}/draw', DrawRoundController::class)
    ->name('draw-rounds.draw');
Route::get('/draw-winners', DrawWinnerController::class)
    ->name('draw-winners.index');
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
Route::get('/gallery', GalleryPhotoController::class)
    ->middleware('user.token')
    ->name('gallery.index');
Route::post('/photos/{photo}/like', PhotoLikeController::class)
    ->middleware('user.token')
    ->name('photos.like.store');
Route::delete('/photos/{photo}/like', PhotoUnlikeController::class)
    ->middleware('user.token')
    ->name('photos.like.destroy');
