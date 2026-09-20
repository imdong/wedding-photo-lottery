<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'app')->name('upload');
Route::view('/gallery', 'app')->name('gallery');
Route::view('/draw', 'app')->name('draw');
Route::view('/admin/review', 'app')->name('admin.review');
