<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits for the heavy answers (SECURITY_REPORT M5): file downloads (Excel / CSV lists, the accounting export, the
 * hotel's data export) 20 a minute per person, reports 60 a minute per person. Normal use stays far below:
 * a report screen asks for a few at a time. Everything else has the general limit (throttle:api, 300 a minute).
 */
class LimitHeavyRequests
{
    public const FILES_PER_MINUTE = 20;

    public const REPORTS_PER_MINUTE = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $who = $request->user()?->id ?? $request->ip();
        $file = in_array($request->query('format'), ['xlsx', 'csv'], true) || $request->is('api/v1/settings/export', 'api/v1/reports/accounting');
        [$key, $max] = match (true) {
            $file => ["files|{$who}", self::FILES_PER_MINUTE],
            $request->is('api/v1/reports', 'api/v1/reports/*', 'api/v1/custom-reports/*') => ["reports|{$who}", self::REPORTS_PER_MINUTE],
            default => [null, 0],
        };
        if ($key !== null) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return response()->json(['message' => $file ? 'Too many downloads in a short time. Wait a minute and try again.' : 'Too many reports in a short time. Wait a minute and try again.', 'code' => 'too_many'], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
            }
            RateLimiter::hit($key, 60);
        }

        return $next($request);
    }
}
