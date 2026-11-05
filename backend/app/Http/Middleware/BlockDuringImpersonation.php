<?php

namespace App\Http\Middleware;

use App\Services\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While HotelMS support is signed in as a hotel user, that user's own
 * password and profile cannot be changed.
 */
class BlockDuringImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && $request->session()->has(Impersonation::SESSION_KEY)) {
            return response()->json([
                'message' => 'Not available while signed in as this user for support.',
                'code' => 'impersonating',
            ], 403);
        }

        return $next($request);
    }
}
