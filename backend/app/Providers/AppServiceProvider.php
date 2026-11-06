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
        // Switch values are read once per request / job.
        $this->app->scoped(\App\Services\Switches::class);
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
        // Per hotel (staff share one network), so a PIN cannot be found by trying them all.
        RateLimiter::for('pin', fn (Request $request) => [
            Limit::perMinute(5)->by('pin|'.($request->user()?->hotel_id ?? $request->ip())),
            Limit::perHour(30)->by('pin-h|'.($request->user()?->hotel_id ?? $request->ip())),
        ]);
    }
}
