<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TokenController;
use App\Http\Controllers\MeController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', RegisterController::class)->middleware('throttle:register');

    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')
        ->name('auth.verify-email');
    Route::post('verify-email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:resend-verification');

    Route::post('login', [LoginController::class, 'login'])->middleware('throttle:login');
    Route::post('login/verify', [LoginController::class, 'verifyCode'])->middleware('throttle:login-code');
    Route::post('refresh', [TokenController::class, 'refresh'])->middleware('throttle:refresh');

    Route::post('password/forgot', [PasswordController::class, 'forgot'])->middleware('throttle:password-forgot');
    Route::post('password/reset', [PasswordController::class, 'reset'])->middleware('throttle:password-reset');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('logout', [TokenController::class, 'logout']);
        Route::post('password/change', [PasswordController::class, 'change'])->middleware('throttle:password-change');
    });
});

// Consentito anche con password da cambiare: l'app deve sapere che va mostrata la schermata di cambio.
Route::get('me', MeController::class)->middleware(['auth:sanctum', 'active']);

// Le rotte di funzionalità (box, ordini, ecc.) vanno in questo gruppo:
// Route::middleware(['auth:sanctum', 'active', 'password.fresh'])->group(function () { ... });
