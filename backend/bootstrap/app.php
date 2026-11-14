<?php

use App\Http\Middleware\BlockDuringImpersonation;
use App\Http\Middleware\DemoTimeLimit;
use App\Http\Middleware\EnforceSessionRules;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureDeviceActive;
use App\Http\Middleware\EnsureHotelPermission;
use App\Http\Middleware\EnsureHotelWritable;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureNoEmergency;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePlatformPermission;
use App\Http\Middleware\EnsureSwitchOn;
use App\Http\Middleware\EnsureTwoFactorSetUp;
use App\Http\Middleware\Idempotent;
use App\Http\Middleware\LimitHeavyRequests;
use App\Http\Middleware\OneAtATime;
use App\Http\Middleware\RecordProblems;
use App\Http\Middleware\ScalarQuery;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetTenantContext;
use App\Services\Watchdog\WatchEvents;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        // Not signed in: the API answers 401 (JSON). There is no server login page to send people to.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
        $middleware->append(SecurityHeaders::class);
        $middleware->append(RecordProblems::class); // for the Super Admin watchdog
        $middleware->appendToGroup('api', DemoTimeLimit::class); // "Try the demo" visits end after X minutes
        $middleware->prependToGroup('api', ScalarQuery::class); // ?q[]=x in a link: "not valid", never a server error

        // Set the hotel before route model binding, so {role} etc. only find
        // the signed-in user's own hotel's records (after authentication).
        $middleware->prependToPriorityList(SubstituteBindings::class, SetTenantContext::class);

        $middleware->alias([
            'tenant' => SetTenantContext::class,
            'session.rules' => EnforceSessionRules::class,
            'heavy' => LimitHeavyRequests::class,
            'active' => EnsureActiveUser::class,
            'device' => EnsureDeviceActive::class,
            'two.factor' => EnsureTwoFactorSetUp::class,
            'one.at.a.time' => OneAtATime::class,
            'idempotent' => Idempotent::class,
            'emergency' => EnsureNoEmergency::class,
            'switch' => EnsureSwitchOn::class,
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
        // Server errors are noted for the watchdog (with the message, so it can say where the problem is).
        $exceptions->report(function (Throwable $e) {
            if (! $e instanceof HttpExceptionInterface && ! $e instanceof ValidationException && ! $e instanceof AuthenticationException) {
                app(WatchEvents::class)->record('exception', request(), 500, class_basename($e).': '.$e->getMessage().' @ '.str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine());
            }
        });
        // A record that is not there (wrong link, removed, or another hotel's): a plain message, no class names.
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $request->is('api/*') && $e->getPrevious() instanceof ModelNotFoundException
            ? response()->json(['message' => 'Not found. It may have been removed, or the link is wrong.', 'code' => 'not_found'], 404)
            : null);
        // A badly typed date anywhere is the person's input, not a server error.
        $exceptions->render(fn (InvalidFormatException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'One of the dates is not valid.', 'code' => 'invalid_date'], 422)
            : null);
    })->create();
