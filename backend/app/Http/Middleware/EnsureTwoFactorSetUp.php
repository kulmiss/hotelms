<?php

namespace App\Http\Middleware;

use App\Services\Impersonation;
use App\Services\Security\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** People who must use two-step sign-in set it up before doing anything else. */
class EnsureTwoFactorSetUp
{
    public function __construct(private TwoFactor $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $supporting = $request->hasSession() && $request->session()->has(Impersonation::SESSION_KEY);

        // Setting it up (and the security page) stays open, of course.
        if ($request->is('api/v1/me/security', 'api/v1/me/security/*')) {
            return $next($request);
        }

        if ($user && ! $supporting && ! $this->twoFactor->enabled($user) && $this->twoFactor->required($user)) {
            return response()->json(['message' => 'Please turn on two-step sign-in first.', 'code' => 'two_factor_setup_required'], 403);
        }

        return $next($request);
    }
}
