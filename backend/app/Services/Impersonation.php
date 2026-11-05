<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\ImpersonationLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Super Admin "log in as hotel" (super-admin.md): never asks for passwords,
 * always has a reason, and is recorded in the platform log and the hotel's
 * own activity log, from start to end.
 */
class Impersonation
{
    public const SESSION_KEY = 'impersonation';

    public function __construct(private ActivityLogger $log) {}

    public function active(Request $request): ?ImpersonationLog
    {
        $id = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return $id ? ImpersonationLog::with('superAdmin:id,name')->find($id) : null;
    }

    public function start(Request $request, User $admin, Hotel $hotel, User $target, string $reason): ImpersonationLog
    {
        $entry = ImpersonationLog::create([
            'super_admin_id' => $admin->id,
            'hotel_id' => $hotel->id,
            'impersonated_user_id' => $target->id,
            'reason' => $reason,
            'started_at' => now(),
            'ip_address' => $request->ip(),
        ]);

        $props = ['reason' => $reason, 'as' => $target->email, 'by' => $admin->email];
        // Platform log (no hotel) and the hotel's own log.
        $this->log->log('support.login_as', "Signed in to {$hotel->name} as {$target->name}", null, $props + ['hotel' => $hotel->name]);
        $this->log->log('support.login_as', "HotelMS support ({$admin->name}) signed in as {$target->name}", $target, $props);

        Auth::guard('web')->login($target);
        $request->session()->put(self::SESSION_KEY, $entry->id);

        return $entry;
    }

    /** Ends the support session and signs the Super Admin back in. */
    public function stop(Request $request): ?User
    {
        $entry = $this->active($request);
        if (! $entry) {
            return null;
        }

        $entry->forceFill(['ended_at' => now()])->save();
        $request->session()->forget(self::SESSION_KEY);

        $target = $entry->impersonated;
        $this->log->log('support.logout', "HotelMS support ({$entry->superAdmin->name}) left the hotel", $target, ['minutes' => (int) $entry->started_at->diffInMinutes(now())]);

        $admin = $entry->superAdmin()->first();
        Auth::guard('web')->login($admin);

        return $admin;
    }
}
