<?php

namespace App\Services\Security;

use App\Mail\NewDeviceMail;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\HotelSettings;
use App\Support\HotelClock;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Devices (decisions.md W7): alert on sign-ins from new devices, list and sign out devices,
 * and optionally allow staff to sign in only on devices a Hotel Admin approved.
 */
class Devices
{
    public const COOKIE = 'hms_device';

    public const SESSION_KEY = 'device_id';

    /** The device token from the cookie, or a new one (sent back with {@see Cookie()}). */
    public function token(Request $request): string
    {
        $token = (string) $request->cookie(self::COOKIE);

        return strlen($token) === 40 && ctype_alnum($token) ? $token : Str::random(40);
    }

    public function cookie(string $token): Cookie
    {
        return cookie(self::COOKIE, $token, 60 * 24 * 365 * 5, null, null, null, true, false, 'lax');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Called after the password is right and before the second step: refuses blocked or unapproved devices. */
    public function check(User $user, string $token, Request $request): void
    {
        $hash = self::hash($token);
        if (UserDevice::where('user_id', $user->id)->where('token_hash', $hash)->where('status', 'blocked')->exists()) {
            throw ValidationException::withMessages(['email' => 'This device was blocked. Ask your Hotel Admin.']);
        }
        if (! $this->needsApproval($user) || $this->approved($user->hotel_id, $hash)) {
            return;
        }
        $device = UserDevice::firstOrNew(['user_id' => $user->id, 'token_hash' => $hash]);
        $first = ! $device->exists;
        $device->fill(['hotel_id' => $user->hotel_id, 'name' => $this->label($request), 'ip' => $request->ip(), 'status' => 'pending', 'last_seen_at' => now()])->save();
        if ($first) {
            $this->tellAdmins($user, $device, true);
        }

        throw ValidationException::withMessages(['email' => 'This device is waiting for approval by your Hotel Admin.'])->status(403);
    }

    /** After a full sign-in: remember the device for this session and alert about new devices. */
    public function signedIn(User $user, string $token, Request $request): UserDevice
    {
        $hash = self::hash($token);
        $device = UserDevice::firstOrNew(['user_id' => $user->id, 'token_hash' => $hash]);
        $isNew = ! $device->exists;
        $hadOthers = $isNew && UserDevice::where('user_id', $user->id)->exists();
        $device->fill([
            'hotel_id' => $user->hotel_id, 'name' => $this->label($request), 'ip' => $request->ip(), 'status' => 'active', 'last_seen_at' => now(),
            'approved_by' => $device->approved_by ?? ($this->approved($user->hotel_id, $hash) ? $this->approverOf($user->hotel_id, $hash) : null),
        ])->save();
        $request->session()->put(self::SESSION_KEY, $device->id);
        if ($hadOthers) {
            $this->tellAdmins($user, $device, false); // the very first sign-in is not "new"
        }

        return $device;
    }

    public function needsApproval(User $user): bool
    {
        if (! $user->hotel || $user->is_super_admin || $user->isHotelAdmin() || $user->hotel->is_demo) {
            return false;
        }

        return (bool) app(TenantContext::class)->runAs($user->hotel, fn () => app(HotelSettings::class)->group(HotelSettings::SECURITY_DEFAULTS)['approved_devices_only']);
    }

    /** A device is approved for the whole hotel once a Hotel Admin approved it for anyone (a shared reception iPad). */
    public function approved(?int $hotelId, string $hash): bool
    {
        return $hotelId !== null && UserDevice::where('hotel_id', $hotelId)->where('token_hash', $hash)->whereNotNull('approved_by')->where('status', '!=', 'blocked')->exists();
    }

    public function label(Request $request): string
    {
        $ua = (string) $request->userAgent();
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $os ? "{$browser} on {$os}" : $browser;
    }

    private function approverOf(?int $hotelId, string $hash): ?int
    {
        return UserDevice::where('hotel_id', $hotelId)->where('token_hash', $hash)->whereNotNull('approved_by')->value('approved_by');
    }

    /** Staff → their Hotel Admins; Hotel Admins and the HotelMS team → themselves. Demo hotels send nothing. */
    private function tellAdmins(User $user, UserDevice $device, bool $waiting): void
    {
        $hotel = $user->hotel;
        if ($hotel?->is_demo) {
            return;
        }
        $to = ($hotel && ! $user->isHotelAdmin())
            ? User::where('hotel_id', $hotel->id)->where('status', 'active')->whereHas('role', fn ($q) => $q->withoutGlobalScope('hotel')->where('key', 'hotel_admin'))->pluck('email')->all()
            : [$user->email];
        $time = $hotel ? HotelClock::local(now(), $hotel)->format('d M Y H:i') : now()->format('d M Y H:i').' UTC';
        $link = rtrim((string) config('app.frontend_url'), '/').($hotel ? '/devices' : '/profile');
        foreach (array_filter($to) as $email) {
            try {
                Mail::to($email)->send(new NewDeviceMail($user->name, $device->name, $device->ip, $time, $waiting, $link));
            } catch (\Throwable $e) {
                report($e); // a mail problem must never block signing in
            }
        }
    }
}
