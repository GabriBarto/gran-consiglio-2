<?php

use App\Http\Controllers\Admin\SellerVerificationController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TokenController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PickupController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\Seller\BoxController;
use App\Http\Controllers\Seller\DocumentController;
use App\Http\Controllers\Seller\EarningsController;
use App\Http\Controllers\Seller\SellerOrderController;
use App\Http\Controllers\Seller\ProfileController;
use App\Http\Controllers\Seller\StripeController;
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

// Ricerca e scheda dei negozi: pubbliche, ci arriva anche chi non ha ancora un account (giorno 7)
Route::middleware('throttle:shops')->group(function () {
    Route::get('shops', [ShopController::class, 'index']);
    Route::get('shops/{seller}', [ShopController::class, 'show'])->whereNumber('seller');
});

// Webhook di Stripe: lo chiama Stripe, non un utente. Protetto dalla verifica della firma (giorno 8)
Route::post('webhooks/stripe', StripeWebhookController::class);

// Consentito anche con password da cambiare: l'app deve sapere che va mostrata la schermata di cambio.
Route::get('me', MeController::class)->middleware(['auth:sanctum', 'active']);

// Le rotte di funzionalità (box, ordini, ecc.) vanno in questi gruppi, uno per ruolo.
Route::middleware(['auth:sanctum', 'active', 'password.fresh'])->group(function () {
    // Onboarding del venditore (giorno 4)
    Route::prefix('seller')->middleware('role:SELLER')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::put('profile', [ProfileController::class, 'update']);
        Route::post('resubmit', [ProfileController::class, 'resubmit']);
        Route::post('documents', [DocumentController::class, 'store']);
        Route::delete('documents/{document}', [DocumentController::class, 'destroy']);
        Route::post('stripe/onboarding', [StripeController::class, 'onboarding']);
        Route::post('stripe/sync', [StripeController::class, 'sync']);

        // Ritiri: ordini del giorno e scansione del QR del cliente (giorno 10)
        Route::get('orders', [SellerOrderController::class, 'index']);
        Route::post('pickup/scan', [PickupController::class, 'scan'])->middleware('throttle:scan');

        // Incassi e pagamenti ricevuti (giorno 11)
        Route::get('earnings', [EarningsController::class, 'index']);

        // Box del giorno (giorno 6)
        Route::get('box-options', [BoxController::class, 'options']);
        Route::get('boxes', [BoxController::class, 'index']);
        Route::post('boxes', [BoxController::class, 'store']);
        Route::get('boxes/{box}', [BoxController::class, 'show'])->whereNumber('box');
        Route::put('boxes/{box}', [BoxController::class, 'update'])->whereNumber('box');
        Route::delete('boxes/{box}', [BoxController::class, 'destroy'])->whereNumber('box');
    });

    // Ordini del cliente: checkout con stock riservato e pagamento (giorno 8)
    Route::prefix('orders')->middleware('role:CUSTOMER')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::post('/', [OrderController::class, 'store'])->middleware('throttle:checkout');
        Route::get('{order}', [OrderController::class, 'show'])->whereNumber('order');
        Route::get('{order}/pickup', [PickupController::class, 'show'])->whereNumber('order');
        Route::post('{order}/simulate-payment', [OrderController::class, 'simulatePayment'])->whereNumber('order');
    });

    // Verifica delle attività da parte dell'admin
    Route::prefix('admin')->middleware('role:ADMIN')->group(function () {
        Route::get('sellers', [SellerVerificationController::class, 'index']);
        Route::get('sellers/{seller}', [SellerVerificationController::class, 'show']);
        Route::get('sellers/{seller}/documents/{document}', [SellerVerificationController::class, 'document']);
        Route::post('sellers/{seller}/approve', [SellerVerificationController::class, 'approve']);
        Route::post('sellers/{seller}/reject', [SellerVerificationController::class, 'reject']);
    });
});
