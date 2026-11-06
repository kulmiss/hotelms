<?php

namespace App\Http\Middleware;

use App\Services\Watchdog\WatchEvents;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** For the watchdog: notes refused requests (403/404) and server errors (5xx) of the API. */
class RecordProblems
{
    public function __construct(private WatchEvents $events) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $status = $response->getStatusCode();
        if ($request->is('api/*') && ($status === 403 || $status === 404 || $status >= 500)) {
            $this->events->record($status >= 500 ? 'error' : ($status === 403 ? 'denied' : 'missing'), $request, $status);
        }

        return $response;
    }
}
