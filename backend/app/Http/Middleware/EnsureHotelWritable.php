<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended hotel is read-only: staff can sign in, view and export,
 * but nothing new can be saved until the subscription is paid.
 */
class EnsureHotelWritable
{
    public function handle(Request $request, Closure $next): Response
    {
        $hotel = $request->user()?->hotel;

        if ($hotel?->isSuspended() && ! $request->isMethodSafe()) {
            return response()->json([
                'message' => 'This hotel is read-only until the subscription is renewed.',
                'code' => 'hotel_suspended',
            ], 423);
        }

        return $next($request);
    }
}
