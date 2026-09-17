<?php

use App\Http\Controllers\GalleryVideoShareController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/gallery/share/{id}/{slug?}', [GalleryVideoShareController::class, 'show'])
    ->whereNumber('id');
