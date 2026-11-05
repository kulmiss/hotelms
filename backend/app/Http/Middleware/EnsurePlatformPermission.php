<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super Admin area: only HotelMS team members with the given permission.
 */
class EnsurePlatformPermission
{
    public function handle(Request $request, Closure $next, string $permission = 'hotels.view'): Response
    {
        if (! $request->user()?->hasPlatformPermission($permission)) {
            return response()->json([
                'message' => 'You do not have permission to do this.',
                'code' => 'forbidden',
            ], 403);
        }

        return $next($request);
    }
}
