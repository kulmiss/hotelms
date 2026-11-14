<?php

namespace App\Http\Middleware;

use App\Services\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session rules on the server, not only in the browser (SECURITY_REPORT M3, M4; owner Q3 14 Nov):
 * - A locked screen is locked here too: until it is unlocked (password or PIN), every call except the few
 *   the lock screen needs answers 423 "screen_locked". Clearing the browser's storage no longer opens it.
 * - A sign-in lasts at most 12 hours in a row (ASVS 3.3), then the person signs in again. "Remember me"
 *   (staff only, 30 days) is the exception: that device stays signed in, each return starts a new 12 hours.
 */
class EnforceSessionRules
{
    public const SIGNED_IN_AT = 'auth.signed_in_at';

    public const REMEMBERED = 'auth.remembered';

    public const LOCKED = 'screen.locked';

    public const MAX_HOURS = 12;

    /** What the lock screen itself needs (method + path below /api/v1/). */
    private const OPEN_WHILE_LOCKED = ['POST auth/unlock', 'POST auth/pin-switch', 'POST auth/logout', 'POST auth/lock', 'GET me', 'POST impersonation/stop'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->hasSession()) {
            return $next($request);
        }
        $session = $request->session();
        $supporting = $session->has(Impersonation::SESSION_KEY);

        // The 12 hours count from a sign-in with the password (AuthController::complete stores the time). Sessions
        // without that time – from before this rule, or brought back by "Remember me" – are not cut off.
        $since = $session->get(self::SIGNED_IN_AT);
        if ($since !== null && ! $session->get(self::REMEMBERED) && now()->timestamp - (int) $since > self::MAX_HOURS * 3600) {
            $impersonation = app(Impersonation::class);
            $impersonation->stop($request);
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();

            return response()->json(['message' => 'You have been signed in for 12 hours. Please sign in again.', 'code' => 'session_expired'], 401);
        }

        if (! $supporting && $session->get(self::LOCKED)) {
            $route = $request->method().' '.substr($request->path(), strlen('api/v1/'));
            if (! in_array($route, self::OPEN_WHILE_LOCKED, true)) {
                return response()->json(['message' => 'The screen is locked. Unlock it with your PIN or password.', 'code' => 'screen_locked'], 423);
            }
        }

        return $next($request);
    }
}
