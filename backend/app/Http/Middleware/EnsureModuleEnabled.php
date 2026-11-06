<?php

namespace App\Http\Middleware;

use App\Services\HotelModules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** An optional module (housekeeping, cashier_close, shifts, attendance) must be in the plan and switched on. */
class EnsureModuleEnabled
{
    public function __construct(private HotelModules $modules) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        $hotel = $request->user()?->hotel;
        if (! $hotel || ! in_array($module, $this->modules->enabled($hotel), true)) {
            return response()->json(['message' => 'This module is switched off for your hotel.', 'code' => 'module_off', 'module' => $module], 403);
        }

        return $next($request);
    }
}
