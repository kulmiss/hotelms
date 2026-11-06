<?php

use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\HotelController as AdminHotelController;
use App\Http\Controllers\Api\Admin\HotelUserController;
use App\Http\Controllers\Api\Admin\ImpersonationController;
use App\Http\Controllers\Api\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Api\Admin\PlatformReportController;
use App\Http\Controllers\Api\Admin\PlatformSettingsController;
use App\Http\Controllers\Api\Admin\SubscriptionPaymentController;
use App\Http\Controllers\Api\Admin\SwitchController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\DemoController;
use App\Http\Controllers\Api\Front\FrontDeskController;
use App\Http\Controllers\Api\Front\GuestController;
use App\Http\Controllers\Api\Front\InvoiceController;
use App\Http\Controllers\Api\Front\ReservationController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Hotel\ActivityLogController;
use App\Http\Controllers\Api\Hotel\PermissionController;
use App\Http\Controllers\Api\Hotel\RoleController;
use App\Http\Controllers\Api\Hotel\SetupController;
use App\Http\Controllers\Api\Hotel\StaffController;
use App\Http\Controllers\Api\ImpersonationStopController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\Modules\AttendanceController;
use App\Http\Controllers\Api\Modules\CashierController;
use App\Http\Controllers\Api\Modules\HousekeepingController;
use App\Http\Controllers\Api\Modules\ShiftController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\Reports\AutomationController;
use App\Http\Controllers\Api\Reports\InsightController;
use App\Http\Controllers\Api\Reports\ReportController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\Settings\BranchSettingsController;
use App\Http\Controllers\Api\Settings\CancellationPolicyController;
use App\Http\Controllers\Api\Settings\ChargeTypeController;
use App\Http\Controllers\Api\Settings\CurrencyController;
use App\Http\Controllers\Api\Settings\ExchangeRateController;
use App\Http\Controllers\Api\Settings\GuestTypeController;
use App\Http\Controllers\Api\Settings\HotelProfileController;
use App\Http\Controllers\Api\Settings\HotelSettingsController;
use App\Http\Controllers\Api\Settings\ImportController;
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
Route::get('/branding/manifest', [BrandingController::class, 'manifest']);

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/demo/login', [DemoController::class, 'login'])->middleware('throttle:login');
Route::post('/auth/two-factor', [AuthController::class, 'twoFactor'])->middleware('throttle:two-factor');
Route::post('/auth/forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:login');
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:login');
Route::post('/auth/two-factor/resend', [AuthController::class, 'resendCode'])->middleware('throttle:two-factor');

Route::middleware(['auth:sanctum', 'active', 'device', 'tenant'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/unlock', [AuthController::class, 'unlock'])->middleware('throttle:pin');
    Route::post('/auth/pin-switch', [AuthController::class, 'pinSwitch'])->middleware(['throttle:pin', 'not.impersonating']);
    Route::get('/me', [MeController::class, 'show']);
    Route::put('/me/password', [MeController::class, 'updatePassword'])->middleware('not.impersonating');
    Route::put('/me', [MeController::class, 'update'])->middleware(['password.changed', 'not.impersonating']);
    Route::get('/telegram', [AutomationController::class, 'telegramStatus']);
    Route::post('/telegram/link', [AutomationController::class, 'telegramLink'])->middleware('not.impersonating');
    Route::delete('/telegram/link', [AutomationController::class, 'telegramUnlink'])->middleware('not.impersonating');
    Route::post('/impersonation/stop', ImpersonationStopController::class);
    Route::get('/setup', [SetupController::class, 'show']); // before the password change too (step 1 is the password)

    // Everything below needs a password the user chose themselves.
    Route::middleware(['password.changed', 'two.factor'])->group(function () {
        Route::get('/search', SearchController::class)->middleware('throttle:60,1');
        // Your own security (open before two-step sign-in is set up: see EnsureTwoFactorSetUp).
        Route::middleware('not.impersonating')->group(function () {
            Route::get('/me/security', [SecurityController::class, 'show']);
            Route::post('/me/security/two-factor', [SecurityController::class, 'beginTwoFactor'])->middleware('throttle:two-factor');
            Route::post('/me/security/two-factor/confirm', [SecurityController::class, 'confirmTwoFactor'])->middleware('throttle:two-factor');
            Route::post('/me/security/two-factor/disable', [SecurityController::class, 'disableTwoFactor'])->middleware('throttle:two-factor');
            Route::post('/me/security/recovery-codes', [SecurityController::class, 'recoveryCodes'])->middleware('throttle:two-factor');
            Route::put('/me/security/pin', [SecurityController::class, 'setPin'])->middleware('throttle:pin');
            Route::post('/me/security/devices/{id}/sign-out', [SecurityController::class, 'signOutMyDevice']);
            Route::post('/me/security/devices/sign-out-others', [SecurityController::class, 'signOutOtherDevices']);
        });

        // Hotel staff. A suspended hotel is read-only.
        Route::middleware(['hotel.writable', 'emergency'])->group(function () {
            Route::get('/branches', [BranchController::class, 'index']);
            Route::put('/setup/steps/{step}', [SetupController::class, 'mark']);
            Route::post('/setup/finish', [SetupController::class, 'finish']);

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
                Route::get('/devices', [SecurityController::class, 'hotelDevices']);
                Route::post('/devices/{id}/{action}', [SecurityController::class, 'deviceAction']);
            });

            // Guests (shared by all branches).
            Route::get('/guests', [GuestController::class, 'index'])->middleware('permission:guests.view');
            Route::get('/guest-types', [GuestController::class, 'types'])->middleware('permission:guests.view');
            Route::get('/guests/{id}', [GuestController::class, 'show'])->middleware('permission:guests.view');
            Route::middleware('permission:guests.create_edit')->group(function () {
                Route::post('/guests', [GuestController::class, 'store']);
                Route::put('/guests/{id}', [GuestController::class, 'update']);
            });
            Route::post('/guests/{id}/blacklist', [GuestController::class, 'blacklist'])->middleware('permission:guests.blacklist');

            // Reservations.
            Route::middleware('permission:reservations.view')->group(function () {
                Route::get('/reservations', [ReservationController::class, 'index']);
                Route::get('/reservations/calendar', [ReservationController::class, 'calendar']);
                Route::get('/reservations/lookups', [ReservationController::class, 'lookups']);
                Route::get('/reservations/availability', [ReservationController::class, 'availability']);
                Route::post('/reservations/quote', [ReservationController::class, 'quote']);
                Route::get('/reservations/{id}', [ReservationController::class, 'show']);
                Route::get('/reservations/{id}/cancellation-terms', [ReservationController::class, 'cancellationTerms']);
                Route::post('/reservations/{id}/payments', [ReservationController::class, 'payment'])->middleware(['idempotent', 'one.at.a.time:reservation']); // payments.take / payments.refund checked inside
            });
            Route::post('/reservations', [ReservationController::class, 'store'])->middleware('permission:reservations.create');
            Route::put('/reservations/{id}', [ReservationController::class, 'update'])->middleware(['permission:reservations.edit', 'one.at.a.time:reservation']);
            Route::post('/reservations/{id}/cancel', [ReservationController::class, 'cancel'])->middleware(['permission:reservations.cancel', 'one.at.a.time:reservation']);
            Route::post('/reservations/{id}/no-show', [ReservationController::class, 'noShow'])->middleware(['permission:reservations.no_show', 'one.at.a.time:reservation']);

            // Front desk.
            Route::middleware('permission:frontdesk.view')->group(function () {
                Route::get('/front-desk/board', [FrontDeskController::class, 'board']);
                Route::get('/front-desk/today', [FrontDeskController::class, 'today']);
            });
            Route::post('/rooms/{id}/status', [FrontDeskController::class, 'roomStatus']); // rooms.change_status / rooms.maintenance checked inside
            Route::middleware('permission:frontdesk.walk_in')->group(function () {
                Route::get('/front-desk/walk-in/rooms', [FrontDeskController::class, 'walkInRooms']);
                Route::post('/front-desk/walk-in', [FrontDeskController::class, 'walkIn'])->middleware('idempotent');
            });
            Route::middleware('permission:frontdesk.check_in')->group(function () {
                Route::get('/reservations/{id}/check-in', [FrontDeskController::class, 'checkInInfo']);
                Route::post('/reservations/{id}/check-in', [FrontDeskController::class, 'checkIn'])->middleware(['idempotent', 'one.at.a.time:reservation']);
            });
            Route::middleware('permission:frontdesk.check_out')->group(function () {
                Route::get('/reservations/{id}/check-out', [FrontDeskController::class, 'checkOutInfo']);
                Route::post('/reservations/{id}/check-out', [FrontDeskController::class, 'checkOut'])->middleware(['idempotent', 'one.at.a.time:reservation']);
            });
            Route::middleware('permission:frontdesk.room_move')->group(function () {
                Route::post('/reservations/{id}/rooms/{roomStay}/move', [FrontDeskController::class, 'move'])->middleware('one.at.a.time:reservation');
                Route::post('/reservations/{id}/extend', [FrontDeskController::class, 'extend'])->middleware('one.at.a.time:reservation');
            });
            Route::post('/reservations/{id}/approve-balance', [FrontDeskController::class, 'approveBalance'])->middleware(['permission:checkout.approve_balance', 'one.at.a.time:reservation']);

            // Billing.
            Route::middleware('permission:invoices.view')->group(function () {
                Route::get('/invoices', [InvoiceController::class, 'index']);
                Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
                Route::get('/charge-types', [InvoiceController::class, 'chargeTypes']);
                Route::post('/invoices/{id}/items', [InvoiceController::class, 'addCharge'])->middleware('one.at.a.time:invoice'); // add_charge / discount checked inside
                Route::delete('/invoices/{id}/items/{item}', [InvoiceController::class, 'removeItem'])->middleware('one.at.a.time:invoice');
                Route::post('/invoices/{id}/payments', [InvoiceController::class, 'payment'])->middleware(['idempotent', 'one.at.a.time:invoice']); // payments.take / refund checked inside
            });
            Route::post('/invoices/{id}/void', [InvoiceController::class, 'void'])->middleware(['permission:invoices.void', 'one.at.a.time:invoice']);
            Route::post('/invoices/{id}/split', [InvoiceController::class, 'split'])->middleware(['permission:invoices.split', 'one.at.a.time:invoice']);
            Route::get('/payments', [InvoiceController::class, 'payments'])->middleware('permission:payments.view');

            // Optional modules (each only when the hotel has it switched on).
            Route::middleware('module:cashier_close')->group(function () {
                Route::middleware('permission:cashier.own_shift')->group(function () {
                    Route::get('/cashier/current', [CashierController::class, 'current']);
                    Route::post('/cashier/open', [CashierController::class, 'open']);
                    Route::post('/cashier/close', [CashierController::class, 'close']);
                });
                Route::get('/cashier/sessions', [CashierController::class, 'index'])->middleware('permission:cashier.own_shift,cashier.view_all');
                Route::get('/cashier/sessions/{id}', [CashierController::class, 'show'])->middleware('permission:cashier.own_shift,cashier.view_all');
                Route::post('/cashier/sessions/{id}/approve', [CashierController::class, 'approve'])->middleware('permission:cashier.approve');
            });
            Route::middleware('module:housekeeping')->group(function () {
                Route::middleware('permission:housekeeping.view')->group(function () {
                    Route::get('/housekeeping/board', [HousekeepingController::class, 'board']);
                    Route::get('/housekeeping/tasks', [HousekeepingController::class, 'index']);
                    Route::post('/housekeeping/tasks/{id}/start', [HousekeepingController::class, 'start']); // own task or assign, checked inside
                    Route::post('/housekeeping/tasks/{id}/done', [HousekeepingController::class, 'done']);
                });
                Route::middleware('permission:housekeeping.assign')->group(function () {
                    Route::get('/housekeeping/staff', [HousekeepingController::class, 'staff']);
                    Route::post('/housekeeping/tasks', [HousekeepingController::class, 'store']);
                    Route::put('/housekeeping/tasks/{id}', [HousekeepingController::class, 'update']);
                });
            });
            Route::middleware('module:shifts')->group(function () {
                Route::get('/shifts/mine', [ShiftController::class, 'mine'])->middleware('permission:shifts.view_own');
                Route::middleware('permission:shifts.manage')->group(function () {
                    Route::get('/shifts', [ShiftController::class, 'index']);
                    Route::post('/shifts', [ShiftController::class, 'store']);
                    Route::put('/shifts/{id}', [ShiftController::class, 'update']);
                    Route::delete('/shifts/{id}', [ShiftController::class, 'destroy']);
                    Route::get('/shifts/roster', [ShiftController::class, 'roster']);
                    Route::post('/shifts/roster', [ShiftController::class, 'assign']);
                    Route::delete('/shifts/roster/{id}', [ShiftController::class, 'unassign']);
                });
            });
            Route::middleware('module:attendance')->group(function () {
                Route::middleware('permission:attendance.clock')->group(function () {
                    Route::get('/attendance/status', [AttendanceController::class, 'status']);
                    Route::post('/attendance/clock', [AttendanceController::class, 'clock']);
                    Route::post('/attendance/kiosk', [AttendanceController::class, 'kiosk']);
                });
                Route::post('/attendance/pin/{user}', [AttendanceController::class, 'setPin']); // self or staff.manage, checked inside
                Route::post('/attendance/manual', [AttendanceController::class, 'manual'])->middleware('permission:attendance.manual');
                Route::get('/attendance/report', [AttendanceController::class, 'report'])->middleware('permission:attendance.report');
            });

            // Dashboard and reports.
            Route::get('/dashboard', [ReportController::class, 'dashboard'])->middleware('permission:dashboard.view');
            Route::get('/reports/drilldown', [ReportController::class, 'drilldown']);
            Route::get('/reports/daily', [InsightController::class, 'daily'])->middleware('permission:reports.revenue');
            Route::get('/reports/forecast', [InsightController::class, 'forecast'])->middleware('permission:reports.forecast');
            Route::get('/reports/targets', [InsightController::class, 'targets'])->middleware('permission:reports.forecast,reports.targets_manage');
            Route::put('/reports/targets', [InsightController::class, 'saveTarget'])->middleware('permission:reports.targets_manage');
            Route::get('/reports/audit', [InsightController::class, 'audit'])->middleware('permission:reports.audit');
            Route::get('/reports/audit/{user}/timeline', [InsightController::class, 'timeline'])->middleware('permission:reports.audit');
            Route::get('/reports/my-shift', [InsightController::class, 'myShift'])->middleware('permission:reports.my_shift');
            Route::get('/reports/my-work', [InsightController::class, 'myWork'])->middleware(['module:housekeeping', 'permission:reports.my_work']);
            Route::get('/reports/accounting', [InsightController::class, 'accounting'])->middleware('permission:reports.accounting_export');
            Route::get('/saved-views', [AutomationController::class, 'views']);
            Route::post('/saved-views', [AutomationController::class, 'saveView']);
            Route::put('/saved-views/{id}', [AutomationController::class, 'saveView']);
            Route::delete('/saved-views/{id}', [AutomationController::class, 'deleteView']);
            Route::middleware(['permission:reports.custom', 'switch:feature.custom_reports'])->group(function () {
                Route::get('/custom-reports', [AutomationController::class, 'customReports']);
                Route::post('/custom-reports', [AutomationController::class, 'saveCustomReport']);
                Route::post('/custom-reports/run', [AutomationController::class, 'runCustom']);
                Route::put('/custom-reports/{id}', [AutomationController::class, 'saveCustomReport']);
                Route::delete('/custom-reports/{id}', [AutomationController::class, 'deleteCustomReport']);
            });
            Route::middleware('permission:reports.schedule')->group(function () {
                Route::get('/report-schedules', [AutomationController::class, 'schedules']);
                Route::post('/report-schedules', [AutomationController::class, 'saveSchedule']);
                Route::put('/report-schedules/{id}', [AutomationController::class, 'saveSchedule']);
                Route::delete('/report-schedules/{id}', [AutomationController::class, 'deleteSchedule']);
                Route::post('/report-schedules/{id}/test', [AutomationController::class, 'testSchedule']);
            });
            Route::middleware('permission:alerts.manage')->group(function () {
                Route::get('/alert-rules', [AutomationController::class, 'alertRules']);
                Route::put('/alert-rules/{id}', [AutomationController::class, 'saveAlertRule']);
            });
            Route::get('/alert-events', [AutomationController::class, 'alertEvents']);
            Route::post('/alert-events/{id}/ack', [AutomationController::class, 'acknowledge']);
            Route::get('/reports/{key}', [ReportController::class, 'show']); // per-report permission checked inside

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
                    Route::get('/import/template', [ImportController::class, 'template']);
                    Route::post('/import/parse', [ImportController::class, 'parse']);
                    Route::post('/import/check', [ImportController::class, 'check']);
                    Route::post('/import', [ImportController::class, 'store']);
                    Route::get('/imports', [ImportController::class, 'index']);
                    Route::post('/imports/{id}/undo', [ImportController::class, 'undo']);
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
                Route::middleware('permission:settings.hotel')->group(function () {
                    Route::get('/security', [SecurityController::class, 'settings']);
                    Route::put('/security', [SecurityController::class, 'updateSettings']);
                    Route::get('/export', [SecurityController::class, 'export']); // Hotel Admin only, checked inside
                });
            });
            Route::get('/subscription', [HotelSettingsController::class, 'subscription'])->middleware('permission:subscription.view');
        });

        // Super Admin (HotelMS team).
        Route::prefix('admin')->middleware('platform:hotels.view')->group(function () {
            Route::get('/dashboard', AdminDashboardController::class);
            Route::get('/reports/platform', [PlatformReportController::class, 'platform']);
            Route::get('/hotels/{hotel}/switches', [SwitchController::class, 'forHotel'])->withTrashed();
            Route::middleware('platform:switches.manage')->group(function () {
                Route::get('/switches', [SwitchController::class, 'index']);
                Route::post('/switches', [SwitchController::class, 'store']);
                Route::delete('/switches/{id}', [SwitchController::class, 'destroy']);
                Route::post('/banners', [SwitchController::class, 'storeBanner']);
                Route::delete('/banners/{id}', [SwitchController::class, 'destroyBanner']);
            });
            Route::get('/reports/hotel-health', [PlatformReportController::class, 'health']);

            Route::get('/hotels', [AdminHotelController::class, 'index']);
            Route::get('/hotels/{hotel}', [AdminHotelController::class, 'show'])->withTrashed(); // closed hotels too
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
                Route::post('/hotels/{hotel}/close', [AdminHotelController::class, 'close']);
                Route::post('/hotels/{hotel}/reopen', [AdminHotelController::class, 'reopen'])->withTrashed();
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
