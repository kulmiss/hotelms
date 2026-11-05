<?php

use Illuminate\Support\Facades\Route;

// The backend is API-only. The web app lives in /frontend.
Route::get('/', fn () => response()->json([
    'app' => config('app.name'),
    'api' => url('/api/v1'),
]));
