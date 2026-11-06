<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hotel staff: the signed-in user's role must have the permission
 * (or one of them: "permission:a,b").
 */
class EnsureHotelPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        if (! $user || ! collect($permissions)->contains(fn ($p) => $user->hasPermission($p))) {
            return response()->json([
                'message' => 'You do not have permission to do this.',
                'code' => 'forbidden',
                'permission' => implode(',', $permissions),
            ], 403);
        }

        return $next($request);
    }
}
