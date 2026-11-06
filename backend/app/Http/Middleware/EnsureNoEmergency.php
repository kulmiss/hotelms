<?php

namespace App\Http\Middleware;

use App\Services\Impersonation;
use App\Services\Switches;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super Admin emergency switches (W4): maintenance mode (all hotels or one) closes the hotel
 * screens with a message; read-only mode lets staff look but not change anything.
 * HotelMS support (log in as hotel) is never locked out, so they can fix things.
 */
class EnsureNoEmergency
{
    public function __construct(private Switches $switches) {}

    public function handle(Request $request, Closure $next): Response
    {
        $hotel = $request->user()?->hotel;
        $support = $request->hasSession() && $request->session()->has(Impersonation::SESSION_KEY);
        if (! $hotel || $support) {
            return $next($request);
        }
        if ($this->switches->on($hotel, 'emergency.maintenance')) {
            return response()->json(['message' => 'The system is being serviced. Please try again in a few minutes.', 'code' => 'maintenance'], 503);
        }
        if ($this->switches->on($hotel, 'emergency.read_only') && ! $request->isMethodSafe()) {
            return response()->json(['message' => 'The system is read-only for a short while. You can look, but not change anything.', 'code' => 'read_only'], 423);
        }

        return $next($request);
    }
}
