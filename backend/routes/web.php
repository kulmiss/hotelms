<?php

use App\Http\Controllers\Web\LandingController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Public website pages (the app itself is app.html, the API is under /api/v1).
// No session, no cookies: visitors are not tracked (owner, 14 Nov 2026).
Route::withoutMiddleware([StartSession::class, EncryptCookies::class, AddQueuedCookiesToResponse::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
    ->group(function () {
        Route::get('/', [LandingController::class, 'index']);
        Route::get('/terms', [LandingController::class, 'legal']);
        Route::get('/privacy', [LandingController::class, 'legal']);
        Route::get('/robots.txt', [LandingController::class, 'robots']);
        Route::get('/sitemap.xml', [LandingController::class, 'sitemap']);
    });
