<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Pagine su cui Stripe riporta il venditore dopo l'onboarding: dicono solo di tornare nell'app.
Route::get('/stripe/return', fn () => view('stripe-return', ['refresh' => false]));
Route::get('/stripe/refresh', fn () => view('stripe-return', ['refresh' => true]));
