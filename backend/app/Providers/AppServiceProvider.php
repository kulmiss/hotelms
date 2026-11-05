<?php

namespace App\Providers;

use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant context per request / job.
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        // Slow down password guessing: 5 tries a minute per email and device.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            strtolower((string) $request->input('email')).'|'.$request->ip()
        ));
        // Codes and PINs are short, so guess attempts are tightly limited (per session and per address).
        RateLimiter::for('two-factor', fn (Request $request) => [
            Limit::perMinute(5)->by('2fa|'.($request->hasSession() ? $request->session()->getId() : '').'|'.$request->ip()),
            Limit::perHour(30)->by('2fa-ip|'.$request->ip()),
        ]);
        RateLimiter::for('pin', fn (Request $request) => [
            Limit::perMinute(5)->by('pin|'.$request->ip()),
            Limit::perHour(40)->by('pin-ip|'.$request->ip()),
        ]);
    }
}
