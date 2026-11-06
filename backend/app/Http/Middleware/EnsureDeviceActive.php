<?php

namespace App\Http\Middleware;

use App\Models\UserDevice;
use App\Services\Security\Devices;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A device that was signed out (by its owner or a Hotel Admin) or blocked loses its
 * session on the very next request. Works with any session driver.
 */
class EnsureDeviceActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->hasSession() ? $request->session()->get(Devices::SESSION_KEY) : null;
        if ($id && $request->user()) {
            $device = UserDevice::find($id);
            if (! $device || $device->status !== 'active') {
                Auth::guard('web')->logout();
                $request->session()->invalidate();

                return response()->json(['message' => 'This device was signed out.', 'code' => 'device_signed_out'], 401);
            }
            if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinutes(5))) {
                $device->forceFill(['last_seen_at' => now(), 'ip' => $request->ip()])->saveQuietly();
            }
        }

        return $next($request);
    }
}
