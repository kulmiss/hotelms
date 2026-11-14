<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\ActivityLogger;
use App\Services\HotelExport;
use App\Services\HotelSettings;
use App\Services\Modules\Attendance;
use App\Services\Security\Devices;
use App\Services\Security\SecurityNotices;
use App\Services\Security\TwoFactor;
use App\Support\HotelClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Your own security (two-step sign-in, PIN, devices), Team → Devices, and Settings → Security. */
class SecurityController extends Controller
{
    public function __construct(private TwoFactor $twoFactor, private ActivityLogger $log) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'two_factor' => $this->twoFactor->enabled($user) ? $user->two_factor_method : null,
            'two_factor_required' => $this->twoFactor->required($user),
            'recovery_codes_left' => $this->twoFactor->recoveryCodesLeft($user),
            'has_pin' => $user->attendance_pin !== null,
            'devices' => $this->devices(UserDevice::where('user_id', $user->id), $request, $user),
        ]]);
    }

    public function beginTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['method' => ['required', Rule::in(TwoFactor::METHODS)], 'password' => ['required', 'string']]);
        $this->checkPassword($request->user(), $data['password']);

        return response()->json(['data' => $this->twoFactor->begin($request->user(), $data['method'])]);
    }

    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $codes = $this->twoFactor->confirm($request->user(), $data['code']);
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'That code is not right, or it expired. Try again.']);
        }
        $this->log->log('security.two_factor_on', "{$request->user()->name} turned on two-step sign-in", $request->user());
        app(SecurityNotices::class)->send($request->user(), 'two_factor_on', $request);

        return response()->json(['recovery_codes' => $codes]);
    }

    public function disableTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        $this->checkPassword($user, $data['password']);
        if ($this->twoFactor->required($user)) {
            throw ValidationException::withMessages(['password' => 'Two-step sign-in is required for your account, so it cannot be turned off.']);
        }
        $this->twoFactor->disable($user);
        $this->log->log('security.two_factor_off', "{$user->name} turned off two-step sign-in", $user);
        app(SecurityNotices::class)->send($user, 'two_factor_off', $request);

        return response()->json(['message' => 'Turned off.']);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        $this->checkPassword($user, $data['password']);
        abort_unless($this->twoFactor->enabled($user), 422, 'Two-step sign-in is off.');
        $codes = $this->twoFactor->newRecoveryCodes($user);
        app(SecurityNotices::class)->send($user, 'recovery_codes', $request);

        return response()->json(['recovery_codes' => $codes]);
    }

    /** Your own PIN: unlocks a shared device and works at the attendance kiosk. */
    public function setPin(Request $request, Attendance $attendance): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'digits_between:4,6'], 'password' => ['required', 'string']]);
        $user = $request->user();
        abort_unless($user->hotel_id !== null, 403);
        $this->checkPassword($user, $data['password']);
        $attendance->setPin($user, $data['pin']);

        return response()->json(['message' => 'PIN saved.']);
    }

    public function signOutMyDevice(Request $request, string $id): JsonResponse
    {
        $device = UserDevice::where('user_id', $request->user()->id)->findOrFail($id);
        $device->update(['status' => 'signed_out']);

        return response()->json(['message' => 'Signed out.']);
    }

    public function signOutOtherDevices(Request $request): JsonResponse
    {
        UserDevice::where('user_id', $request->user()->id)->where('status', 'active')
            ->whereKeyNot((int) $request->session()->get(Devices::SESSION_KEY))->update(['status' => 'signed_out']);

        return response()->json(['message' => 'Signed out everywhere else.']);
    }

    // ------------------------------------------------------------ Team → Devices (Hotel Admin)

    public function hotelDevices(Request $request): JsonResponse
    {
        $hotelId = $request->user()->hotel_id;
        $q = UserDevice::where('hotel_id', $hotelId)->whereIn('user_id', User::where('hotel_id', $hotelId)->select('id'))->where('status', '!=', 'signed_out')->with('user:id,name');

        return response()->json(['data' => $this->devices($q, $request, $request->user(), true)]);
    }

    public function deviceAction(Request $request, string $id, string $action): JsonResponse
    {
        abort_unless(in_array($action, ['approve', 'sign-out', 'block', 'unblock'], true), 404);
        $me = $request->user();
        $device = UserDevice::where('hotel_id', $me->hotel_id)->whereIn('user_id', User::where('hotel_id', $me->hotel_id)->select('id'))->findOrFail($id);
        $same = UserDevice::where('hotel_id', $me->hotel_id)->where('token_hash', $device->token_hash);

        match ($action) {
            // Approving or blocking a device covers everyone who uses it (a shared reception iPad).
            'approve' => $same->update(['approved_by' => $me->id, 'status' => DB::raw("CASE WHEN status = 'pending' THEN 'signed_out' ELSE status END")]),
            'block' => $same->update(['status' => 'blocked', 'approved_by' => null]),
            'unblock' => $same->where('status', 'blocked')->update(['status' => 'signed_out']),
            'sign-out' => $device->update(['status' => 'signed_out']),
        };
        $this->log->log("security.device_{$action}", ucfirst(str_replace('-', ' ', $action))." device {$device->name} of ".($device->user?->name ?? '—'), $device->user);

        return response()->json(['message' => 'Done.']);
    }

    // ------------------------------------------------------------ Settings → Security

    public function settings(HotelSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->group(HotelSettings::SECURITY_DEFAULTS)]);
    }

    public function updateSettings(Request $request, HotelSettings $settings): JsonResponse
    {
        $data = $request->validate([
            'require_two_factor_staff' => ['required', 'boolean'],
            'approved_devices_only' => ['required', 'boolean'],
            'screen_lock_minutes' => ['required', 'integer', Rule::in([0, 5, 10, 15, 30, 60])],
        ]);
        $settings->put($data);
        $this->log->log('settings.security', 'Security settings changed', null, ['new' => $data]);

        return $this->settings($settings);
    }

    // ------------------------------------------------------------ download all our data

    /** Hotel Admin: one ZIP with all the hotel's data (at any time, also when suspended). */
    public function export(Request $request, HotelExport $export)
    {
        $user = $request->user();
        abort_unless($user->isHotelAdmin(), 403, 'Only a Hotel Admin can download all data.');
        $path = $export->zip($user->hotel);
        $this->log->log('hotel.export', "{$user->name} downloaded all hotel data", $user->hotel);
        $name = 'hotel-data-'.now()->format('Y-m-d').'.zip';

        return response()->download($path, $name, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    // ------------------------------------------------------------ helpers

    private function checkPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'That password is not right.']);
        }
    }

    private function devices($query, Request $request, User $viewer, bool $withUser = false): array
    {
        $current = (int) $request->session()->get(Devices::SESSION_KEY);
        $hotel = $viewer->hotel;

        return $query->orderByRaw("FIELD(status, 'pending', 'active', 'blocked', 'signed_out')")->orderByDesc('last_seen_at')->limit(200)->get()
            ->map(fn (UserDevice $d) => [
                'id' => $d->id, 'name' => $d->name, 'ip' => $d->ip, 'status' => $d->status, 'approved' => $d->approved_by !== null,
                'last_seen' => $d->last_seen_at ? HotelClock::iso($d->last_seen_at, $hotel) : null,
                'this_device' => $d->id === $current,
                'user' => $withUser ? ['id' => $d->user_id, 'name' => $d->user?->name] : null,
            ])->values()->all();
    }
}
