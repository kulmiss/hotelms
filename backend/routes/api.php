<?php

use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\HotelController as AdminHotelController;
use App\Http\Controllers\Api\Admin\HotelUserController;
use App\Http\Controllers\Api\Admin\ImpersonationController;
use App\Http\Controllers\Api\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Api\Admin\PlatformSettingsController;
use App\Http\Controllers\Api\Admin\SubscriptionPaymentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\DemoController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Hotel\ActivityLogController;
use App\Http\Controllers\Api\Hotel\PermissionController;
use App\Http\Controllers\Api\Hotel\RoleController;
use App\Http\Controllers\Api\Hotel\StaffController;
use App\Http\Controllers\Api\ImpersonationStopController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\Settings\BranchSettingsController;
use App\Http\Controllers\Api\Settings\CancellationPolicyController;
use App\Http\Controllers\Api\Settings\ChargeTypeController;
use App\Http\Controllers\Api\Settings\CurrencyController;
use App\Http\Controllers\Api\Settings\ExchangeRateController;
use App\Http\Controllers\Api\Settings\GuestTypeController;
use App\Http\Controllers\Api\Settings\HotelProfileController;
use App\Http\Controllers\Api\Settings\HotelSettingsController;
use App\Http\Controllers\Api\Settings\PaymentMethodController;
use App\Http\Controllers\Api\Settings\RateRuleController;
use App\Http\Controllers\Api\Settings\RoomController;
use App\Http\Controllers\Api\Settings\RoomTypeController;
use App\Http\Controllers\Api\Settings\TaxController;
use Illuminate\Support\Facades\Route;

/*
| All API routes live under /api/v1 (see bootstrap/app.php).
*/

Route::get('/health', HealthController::class)->name('api.health');
Route::get('/branding', BrandingController::class);

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/demo/login', [DemoController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'active', 'tenant'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [MeController::class, 'show']);
    Route::put('/me/password', [MeController::class, 'updatePassword'])->middleware('not.impersonating');
    Route::put('/me', [MeController::class, 'update'])->middleware(['password.changed', 'not.impersonating']);
    Route::post('/impersonation/stop', ImpersonationStopController::class);

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

            // Settings (phase 2a)
            Route::prefix('settings')->group(function () {
                Route::middleware('permission:settings.hotel')->group(function () {
                    Route::get('/hotel', [HotelProfileController::class, 'show']);
                    Route::put('/hotel', [HotelProfileController::class, 'update']);
                    Route::post('/hotel/logo', [HotelProfileController::class, 'logo']);
                });
                Route::middleware('permission:settings.branches')->group(function () {
                    Route::get('/branches', [BranchSettingsController::class, 'index']);
                    Route::post('/branches', [BranchSettingsController::class, 'store']);
                    Route::put('/branches/{id}', [BranchSettingsController::class, 'update']);
                    Route::delete('/branches/{id}', [BranchSettingsController::class, 'destroy']);
                });
                Route::middleware('permission:settings.money')->group(function () {
                    foreach (['currencies' => CurrencyController::class, 'taxes' => TaxController::class, 'payment-methods' => PaymentMethodController::class] as $uri => $controller) {
                        Route::get("/{$uri}", [$controller, 'index']);
                        Route::post("/{$uri}", [$controller, 'store']);
                        Route::put("/{$uri}/{id}", [$controller, 'update']);
                        Route::delete("/{$uri}/{id}", [$controller, 'destroy']);
                    }
                });
                Route::middleware('permission:settings.exchange_rates')->group(function () {
                    Route::get('/exchange-rates', [ExchangeRateController::class, 'index']);
                    Route::post('/exchange-rates', [ExchangeRateController::class, 'store']);
                });
                Route::middleware('permission:settings.charges')->group(function () {
                    Route::get('/charge-types', [ChargeTypeController::class, 'index']);
                    Route::post('/charge-types', [ChargeTypeController::class, 'store']);
                    Route::put('/charge-types/{id}', [ChargeTypeController::class, 'update']);
                    Route::delete('/charge-types/{id}', [ChargeTypeController::class, 'destroy']);
                });
                Route::middleware('permission:settings.policies')->group(function () {
                    Route::get('/booking-rules', [HotelSettingsController::class, 'bookingRules']);
                    Route::put('/booking-rules', [HotelSettingsController::class, 'updateBookingRules']);
                    Route::get('/cancellation-policies', [CancellationPolicyController::class, 'index']);
                    Route::post('/cancellation-policies', [CancellationPolicyController::class, 'store']);
                    Route::put('/cancellation-policies/{id}', [CancellationPolicyController::class, 'update']);
                    Route::delete('/cancellation-policies/{id}', [CancellationPolicyController::class, 'destroy']);
                });
                Route::middleware('permission:settings.rooms')->group(function () {
                    Route::get('/room-types', [RoomTypeController::class, 'index']);
                    Route::post('/room-types', [RoomTypeController::class, 'store']);
                    Route::put('/room-types/{id}', [RoomTypeController::class, 'update']);
                    Route::delete('/room-types/{id}', [RoomTypeController::class, 'destroy']);
                    Route::get('/rooms', [RoomController::class, 'index']);
                    Route::post('/rooms', [RoomController::class, 'store']);
                    Route::post('/rooms/generate', [RoomController::class, 'generate']);
                    Route::put('/rooms/{id}', [RoomController::class, 'update']);
                    Route::delete('/rooms/{id}', [RoomController::class, 'destroy']);
                });
                Route::middleware('permission:settings.pricing')->group(function () {
                    Route::get('/guest-types', [GuestTypeController::class, 'index']);
                    Route::post('/guest-types', [GuestTypeController::class, 'store']);
                    Route::put('/guest-types/{id}', [GuestTypeController::class, 'update']);
                    Route::delete('/guest-types/{id}', [GuestTypeController::class, 'destroy']);
                    Route::get('/rate-rules', [RateRuleController::class, 'index']);
                    Route::post('/rate-rules', [RateRuleController::class, 'store']);
                    Route::put('/rate-rules/{id}', [RateRuleController::class, 'update']);
                    Route::delete('/rate-rules/{id}', [RateRuleController::class, 'destroy']);
                    Route::get('/price-preview', [RateRuleController::class, 'preview']);
                });
                Route::middleware('permission:settings.modules')->group(function () {
                    Route::get('/modules', [HotelSettingsController::class, 'modules']);
                    Route::put('/modules', [HotelSettingsController::class, 'updateModules']);
                });
                Route::middleware('permission:settings.attendance')->group(function () {
                    Route::get('/attendance', [HotelSettingsController::class, 'attendance']);
                    Route::put('/attendance', [HotelSettingsController::class, 'updateAttendance']);
                });
            });
            Route::get('/subscription', [HotelSettingsController::class, 'subscription'])->middleware('permission:subscription.view');
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

            Route::post('/hotels/{hotel}/impersonate', [ImpersonationController::class, 'start'])
                ->middleware('platform:hotels.impersonate');
            Route::post('/hotels/{hotel}/demo/reset', [DemoController::class, 'reset'])
                ->middleware('platform:hotels.create');

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
