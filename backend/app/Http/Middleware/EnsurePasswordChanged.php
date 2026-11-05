<?php

namespace App\Http\Middleware;

use App\Services\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Users with a temporary password must set their own before doing anything else.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $supporting = $request->hasSession() && $request->session()->has(Impersonation::SESSION_KEY);

        if ($request->user()?->must_change_password && ! $supporting) {
            return response()->json([
                'message' => 'Please set a new password first.',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
