<?php

// Routes built into the package. They are loaded inside the Brume route group
// (prefix, domain and middleware from config/brume.php, plus HandleBrumeRequests).
// Project-specific admin routes live in the project's own routes/brume.php,
// loaded in the same group behind brume.auth.

use Brume\Http\Controllers\AuthController;
use Brume\Http\Controllers\CaptchaController;
use Brume\Uploads\UploadController;
use Illuminate\Support\Facades\Route;

Route::name('brume.')->group(function () {
    Route::middleware('brume.guest')->group(function () {
        Route::get('login', [AuthController::class, 'show'])->name('login');
        Route::post('login', [AuthController::class, 'login'])->name('login.attempt');
    });

    Route::middleware('brume.auth')->group(function () {
        Route::get('_brume/uploads/{token}', [UploadController::class, 'preview'])->name('uploads.preview');
        Route::get('_brume/captcha/{config?}', [CaptchaController::class, 'show'])->where('config', '[A-Za-z0-9_]{1,32}')->name('captcha');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', config('brume.home'))->name('home');
    });
});
