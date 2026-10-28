<?php

namespace App\Providers;

use App\Support\Clock;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Clock::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(10)->letters()->numbers());

        $byEmailAndIp = fn (Request $request) => strtolower((string) $request->input('email')).'|'.$request->ip();

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)->by($request->ip()));
        RateLimiter::for('resend-verification', fn (Request $request) => Limit::perHour(5)->by($byEmailAndIp($request)));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($byEmailAndIp($request)));
        RateLimiter::for('login-code', fn (Request $request) => Limit::perMinute(10)->by($request->input('challenge_id').'|'.$request->ip()));
        RateLimiter::for('refresh', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('password-forgot', fn (Request $request) => Limit::perHour(5)->by($byEmailAndIp($request)));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by($byEmailAndIp($request)));
        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)->by((string) $request->user()?->id));
    }
}
