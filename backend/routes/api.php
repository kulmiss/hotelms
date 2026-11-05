<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

/*
| All API routes live under /api/v1 (see bootstrap/app.php).
| Phase 1 adds auth (Sanctum), hotels, branches, roles and staff.
*/

Route::get('/health', HealthController::class)->name('api.health');
