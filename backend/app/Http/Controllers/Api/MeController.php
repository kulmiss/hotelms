<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\Hotel;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\HotelModules;
use App\Services\HotelSettings;
use App\Services\HotelSetup;
use App\Services\Impersonation;
use App\Services\Security\EmailChanges;
use App\Services\Security\SecurityLog;
use App\Services\Security\SecurityNotices;
use App\Services\Security\TwoFactor;
use App\Services\Switches;
use App\Support\PasswordRules;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MeController extends Controller
{
    /**
     * Everything the app needs to know about the signed-in user.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $hotel = $user->hotel;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'calendar' => $user->calendar,
                'is_super_admin' => $user->is_super_admin,
                'must_change_password' => $user->must_change_password,
            ],
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
                'is_hotel_admin' => $user->isHotelAdmin(),
                'max_discount_percent' => $user->role->max_discount_percent,
            ] : null,
            'permissions' => $user->permissionKeys(),
            'platform' => $user->is_super_admin ? [
                'role' => $user->platform_role,
                'permissions' => array_values(array_filter(
                    array_keys(config('hotelms.platform_permissions')),
                    fn ($p) => $user->hasPlatformPermission($p),
                )),
            ] : null,
            'impersonation' => ($support = app(Impersonation::class)->active($request)) ? [
                'by' => $support->superAdmin->name,
                'reason' => $support->reason,
                'started_at' => $support->started_at->toIso8601String(),
            ] : null,
            'hotel' => $hotel ? [
                'id' => $hotel->id,
                'name' => $hotel->name,
                'status' => $hotel->status,
                'default_currency' => $hotel->default_currency,
                'timezone' => $hotel->timezone,
                'calendar' => app(TenantContext::class)->runAs($hotel, fn () => app(HotelSettings::class)->get('calendar', 'gregorian')),
                'is_demo' => $hotel->is_demo,
                // A "Try the demo" visit: when it ends (the screen counts down).
                'demo_ends_at' => $request->hasSession() && $request->session()->get('demo_until') ? now()->setTimestamp((int) $request->session()->get('demo_until'))->toIso8601String() : null,
                'modules' => app(HotelModules::class)->enabled($hotel),
                'setup' => $user->isHotelAdmin() ? $this->setup($hotel, $user) : null,
            ] : null,
            'security' => $this->security($request, $user, $hotel),
            // Feature switches the screens need (W4); banners from HotelMS.
            'switches' => $hotel ? collect(app(Switches::class)->all($hotel))->filter(fn ($v, $k) => str_starts_with($k, 'feature.'))->map(fn ($v) => (bool) $v['value'])->mapWithKeys(fn ($v, $k) => [substr($k, 8) => $v]) : (object) [],
            // Emergency switches: the app shows a maintenance screen or a read-only notice (support is never locked out).
            'emergency' => $hotel ? [
                'maintenance' => app(Switches::class)->on($hotel, 'emergency.maintenance'),
                'read_only' => app(Switches::class)->on($hotel, 'emergency.read_only'),
            ] : null,
            'banners' => $hotel ? Banner::active()->latest()->get()->filter(fn (Banner $b) => $b->isFor($hotel))->map(fn (Banner $b) => ['id' => $b->id, 'message' => $b->message, 'tone' => $b->tone])->values() : [],
            'branches' => $user->accessibleBranches()
                ->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name])
                ->values(),
        ]);
    }

    /** What the app needs for two-step sign-in and the screen lock. */
    private function security(Request $request, User $user, ?Hotel $hotel): array
    {
        $tf = app(TwoFactor::class);
        $supporting = $request->hasSession() && $request->session()->has(Impersonation::SESSION_KEY);
        $lock = $hotel && ! $hotel->is_demo ? (int) app(HotelSettings::class)->group(HotelSettings::SECURITY_DEFAULTS)['screen_lock_minutes'] : 0;

        return [
            'two_factor' => $tf->enabled($user) ? $user->two_factor_method : null,
            'two_factor_setup_required' => ! $supporting && ! $tf->enabled($user) && $tf->required($user),
            'screen_lock_minutes' => $supporting ? 0 : $lock,
            'has_pin' => $user->attendance_pin !== null,
            // The password used at sign-in is weaker than today's rules: a note asks to change it (not forced).
            'password_weak' => ! $supporting && $request->hasSession() && (bool) $request->session()->get(AuthController::WEAK),
        ];
    }

    /** @return array{completed: bool, started: bool, skipped: int, remaining: int} */
    private function setup(Hotel $hotel, User $user): array
    {
        $o = app(HotelSetup::class)->overview($hotel, $user);

        return ['completed' => $o['completed'], 'started' => $o['started'], 'skipped' => $o['skipped'], 'remaining' => $o['remaining']];
    }

    /** Edit your own profile (email is the sign-in name, so it is changed by an admin). */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'calendar' => ['sometimes', 'nullable', 'in:'.implode(',', HotelSettings::CALENDARS)],
        ]);

        $request->user()->update($data);

        return $this->show($request);
    }

    /**
     * The platform Owner changes their own sign-in email (nobody above them can).
     * Needs the current password; the old address is told about it.
     */
    public function updateEmail(Request $request, ActivityLogger $log): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->is_super_admin && $user->platform_role === 'owner', 403, 'Only the Owner can change their own email here.');

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['required', 'string'],
        ]);
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is not correct.']);
        }

        // The new address must confirm it (a link, 24 hours); then the old address is told (SECURITY_REPORT M7).
        $result = app(EmailChanges::class)->request($user, $data['email'], $user);
        if ($result === 'pending') {
            $log->log('platform.email_change_requested', "Sign-in email change to {$user->pending_email} asked (waits for the link)", $user);
            app(SecurityLog::class)->record('email_change_requested', $user, $request, $user->pending_email);
        }

        $response = $this->show($request);

        return $response->setData(array_merge($response->getData(true), ['email_change' => $result]));
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => PasswordRules::forNew($user->email),
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is not correct.']);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password different from the current one.']);
        }

        $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
        ])->save();
        $request->session()->forget(AuthController::WEAK);
        app(SecurityNotices::class)->send($user, 'password_changed', $request);
        app(SecurityLog::class)->record('password_changed', $user, $request);

        return response()->json(['message' => 'Password changed.']);
    }
}
