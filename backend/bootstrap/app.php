<?php

use App\Http\Middleware\BlockDuringImpersonation;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureDeviceActive;
use App\Http\Middleware\EnsureHotelPermission;
use App\Http\Middleware\EnsureHotelWritable;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePlatformPermission;
use App\Http\Middleware\EnsureTwoFactorSetUp;
use App\Http\Middleware\OneAtATime;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The React app signs in with Sanctum's cookie session.
        $middleware->statefulApi();
        $middleware->append(SecurityHeaders::class);

        // Set the hotel before route model binding, so {role} etc. only find
        // the signed-in user's own hotel's records (after authentication).
        $middleware->prependToPriorityList(SubstituteBindings::class, SetTenantContext::class);

        $middleware->alias([
            'tenant' => SetTenantContext::class,
            'active' => EnsureActiveUser::class,
            'device' => EnsureDeviceActive::class,
            'two.factor' => EnsureTwoFactorSetUp::class,
            'one.at.a.time' => OneAtATime::class,
            'not.impersonating' => BlockDuringImpersonation::class,
            'password.changed' => EnsurePasswordChanged::class,
            'module' => EnsureModuleEnabled::class,
            'hotel.writable' => EnsureHotelWritable::class,
            'platform' => EnsurePlatformPermission::class,
            'permission' => EnsureHotelPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
