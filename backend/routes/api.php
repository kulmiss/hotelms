<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MeController;
use Illuminate\Support\Facades\Route;

/*
| All API routes live under /api/v1 (see bootstrap/app.php).
*/

Route::get('/health', HealthController::class)->name('api.health');

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [MeController::class, 'show']);
    Route::put('/me/password', [MeController::class, 'updatePassword']);

    // Everything below needs a password the user chose themselves.
    Route::middleware('password.changed')->group(function () {
        Route::get('/branches', [BranchController::class, 'index']);
    });
});
