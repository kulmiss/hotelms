<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Try the demo" visits last a few minutes (owner, 2026-11-07; Super Admin sets the minutes, default 30).
 * The demo sign-in stores the end time in the session; after it, the visitor is signed out.
 * Super Admin "log in as hotel" never sets it, so support work in the demo hotel is not cut off.
 */
class DemoTimeLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && ($until = $request->session()->get('demo_until')) && now()->getTimestamp() > (int) $until) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'The demo time is over.', 'code' => 'demo_over', 'demo_over' => true], 401);
        }

        return $next($request);
    }
}
