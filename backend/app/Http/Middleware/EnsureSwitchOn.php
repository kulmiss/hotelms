<?php

namespace App\Http\Middleware;

use App\Services\Switches;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A feature switched off by the Super Admin (W4) answers 403 with code feature_off. */
class EnsureSwitchOn
{
    public function __construct(private Switches $switches) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        if (! $this->switches->on($request->user()?->hotel, $key)) {
            return response()->json(['message' => 'This feature is not available for your hotel.', 'code' => 'feature_off'], 403);
        }

        return $next($request);
    }
}
