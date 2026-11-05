<?php

use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\HotelController as AdminHotelController;
use App\Http\Controllers\Api\Admin\HotelUserController;
use App\Http\Controllers\Api\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Api\Admin\PlatformSettingsController;
use App\Http\Controllers\Api\Admin\SubscriptionPaymentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Hotel\ActivityLogController;
use App\Http\Controllers\Api\Hotel\PermissionController;
use App\Http\Controllers\Api\Hotel\RoleController;
use App\Http\Controllers\Api\Hotel\StaffController;
use App\Http\Controllers\Api\MeController;
use Illuminate\Support\Facades\Route;

/*
| All API routes live under /api/v1 (see bootstrap/app.php).
*/

Route::get('/health', HealthController::class)->name('api.health');
Route::get('/branding', BrandingController::class);

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'active', 'tenant'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [MeController::class, 'show']);
    Route::put('/me/password', [MeController::class, 'updatePassword']);
    Route::put('/me', [MeController::class, 'update'])->middleware('password.changed');

    // Everything below needs a password the user chose themselves.
    Route::middleware('password.changed')->group(function () {

        // Hotel staff. A suspended hotel is read-only.
        Route::middleware('hotel.writable')->group(function () {
            Route::get('/branches', [BranchController::class, 'index']);

            Route::middleware('permission:roles.manage')->group(function () {
                Route::get('/permissions', [PermissionController::class, 'index']);
                Route::get('/roles/{role}', [RoleController::class, 'show']);
                Route::post('/roles', [RoleController::class, 'store']);
                Route::put('/roles/{role}', [RoleController::class, 'update']);
                Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
            });
            // Staff managers need the role list to pick a role.
            Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:staff.view');

            Route::get('/staff', [StaffController::class, 'index'])->middleware('permission:staff.view');
            Route::get('/staff/{user}', [StaffController::class, 'show'])->middleware('permission:staff.view');
            Route::middleware('permission:staff.manage')->group(function () {
                Route::post('/staff', [StaffController::class, 'store']);
                Route::put('/staff/{user}', [StaffController::class, 'update']);
                Route::post('/staff/{user}/disable', [StaffController::class, 'disable']);
                Route::post('/staff/{user}/enable', [StaffController::class, 'enable']);
                Route::post('/staff/{user}/reset-password', [StaffController::class, 'resetPassword']);
            });

            Route::get('/activity-log', [ActivityLogController::class, 'index'])->middleware('permission:activity_log.view');
        });

        // Super Admin (HotelMS team).
        Route::prefix('admin')->middleware('platform:hotels.view')->group(function () {
            Route::get('/dashboard', AdminDashboardController::class);

            Route::get('/hotels', [AdminHotelController::class, 'index']);
            Route::get('/hotels/{hotel}', [AdminHotelController::class, 'show']);
            Route::get('/plans', [AdminPlanController::class, 'index']);
            Route::get('/settings', [PlatformSettingsController::class, 'show']);

            Route::middleware('platform:hotels.create')->group(function () {
                Route::post('/hotels', [AdminHotelController::class, 'store']);
                Route::put('/hotels/{hotel}', [AdminHotelController::class, 'update']);
                Route::post('/hotels/{hotel}/trial', [AdminHotelController::class, 'setTrial']);
            });

            Route::post('/hotels/{hotel}/users/{user}/reset-password', [HotelUserController::class, 'resetPassword'])
                ->middleware('platform:users.reset_password');

            Route::middleware('platform:billing.manage')->group(function () {
                Route::post('/hotels/{hotel}/suspend', [AdminHotelController::class, 'suspend']);
                Route::post('/hotels/{hotel}/activate', [AdminHotelController::class, 'activate']);
                Route::get('/hotels/{hotel}/payments/quote', [SubscriptionPaymentController::class, 'quote']);
                Route::post('/hotels/{hotel}/payments', [SubscriptionPaymentController::class, 'store']);
                Route::post('/plans', [AdminPlanController::class, 'store']);
                Route::put('/plans/{plan}', [AdminPlanController::class, 'update']);
                Route::put('/settings', [PlatformSettingsController::class, 'update']);
                Route::post('/settings/logo', [PlatformSettingsController::class, 'logo']);
            });
        });
    });
});
