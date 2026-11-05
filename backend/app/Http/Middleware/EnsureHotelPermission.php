<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hotel staff: the signed-in user's role must have the permission.
 */
class EnsureHotelPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $request->user()?->hasPermission($permission)) {
            return response()->json([
                'message' => 'You do not have permission to do this.',
                'code' => 'forbidden',
                'permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
