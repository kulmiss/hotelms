<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Impersonation;
use App\Services\Modules\Attendance;
use App\Services\Security\Devices;
use App\Services\Security\TwoFactor;
use App\Services\Watchdog\WatchEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Session key of a sign-in that passed the password and waits for the second step. */
    private const PENDING = 'login.pending';

    private const PENDING_MINUTES = 10;

    public function __construct(private Devices $devices, private TwoFactor $twoFactor) {}

    /**
     * Web sign-in (Sanctum cookie session). With two-step sign-in on, the answer is
     * {two_factor: "app"|"email"} and the code goes to POST /auth/two-factor.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Auth::guard('web')->validate(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            app(WatchEvents::class)->record('login_failed', $request, 422, mb_strtolower($credentials['email']));
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'This account is disabled. Please contact your hotel admin.']);
        }

        $token = $this->devices->token($request);
        $this->devices->check($user, $token, $request);

        if ($this->twoFactor->enabled($user)) {
            $request->session()->put(self::PENDING, ['user' => $user->id, 'remember' => $request->boolean('remember'), 'at' => now()->timestamp]);
            if ($user->two_factor_method === 'email') {
                $this->twoFactor->sendEmailCode($user);
            }

            return response()->json(['two_factor' => $user->two_factor_method, 'email' => $user->two_factor_method === 'email' ? $this->twoFactor->maskEmail($user->email) : null])
                ->withCookie($this->devices->cookie($token));
        }

        return $this->complete($request, $user, $request->boolean('remember'), $token);
    }

    /** Second step: a code from the app or email, or a recovery code. */
    public function twoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $this->pendingUser($request);

        // Per person too (not only per session and address): codes cannot be guessed from many places.
        $key = '2fa-fails|'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $request->session()->forget(self::PENDING);
            throw ValidationException::withMessages(['code' => 'Too many wrong codes. Wait 15 minutes, then sign in again.']);
        }
        if (! $this->twoFactor->verify($user, $data['code'])) {
            RateLimiter::hit($key, 15 * 60);
            throw ValidationException::withMessages(['code' => 'That code is not right, or it expired.']);
        }
        RateLimiter::clear($key);
        $pending = $request->session()->pull(self::PENDING);
        if (str_contains($data['code'], '-')) {
            app(ActivityLogger::class)->log('security.recovery_code_used', "{$user->name} signed in with a recovery code", $user);
        }

        return $this->complete($request, $user, (bool) $pending['remember'], $this->devices->token($request));
    }

    public function resendCode(Request $request): JsonResponse
    {
        $user = $this->pendingUser($request);
        abort_unless($user->two_factor_method === 'email', 422, 'Your codes come from an app.');
        $this->twoFactor->sendEmailCode($user);

        return response()->json(['message' => 'A new code was sent.']);
    }

    /**
     * Shared reception device: after the screen locked, another staff member of the same hotel
     * takes over with their 4-digit PIN (the same PIN as the attendance kiosk).
     * Not for admins: a PIN is too short to protect an admin account.
     */
    public function pinSwitch(Request $request): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'digits_between:4,6']]);
        $current = $request->user();
        abort_if(! $current->hotel_id || $request->session()->has(Impersonation::SESSION_KEY), 403);

        $user = app(Attendance::class)->findByPin((int) $current->hotel_id, $data['pin']);
        if (! $user || $user->isHotelAdmin() || ($this->twoFactor->enabled($user) && $user->id !== $current->id)) {
            throw ValidationException::withMessages(['pin' => 'This PIN cannot be used here. Sign in with your password.']);
        }
        if ($user->id === $current->id) {
            return response()->json(['message' => 'Unlocked.']);
        }
        $token = $this->devices->token($request);
        $this->devices->check($user, $token, $request);
        app(ActivityLogger::class)->log('security.pin_switch', "{$user->name} took over the device from {$current->name}", $user);

        return $this->complete($request, $user, false, $token);
    }

    /** Unlock your own locked screen with your password (anyone, including admins). */
    public function unlock(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'That password is not right.']);
        }

        return response()->json(['message' => 'Unlocked.']);
    }

    public function logout(Request $request, Impersonation $impersonation): JsonResponse
    {
        $impersonation->stop($request); // ends a support session properly, if any
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }

    private function complete(Request $request, User $user, bool $remember, string $token): JsonResponse
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $request->session()->forget(self::PENDING);
        // The session belongs to this person now (also after a PIN switch from someone else).
        $request->session()->put('password_hash_web', $user->getAuthPassword());
        $user->forceFill(['last_login_at' => now()])->save();
        $this->devices->signedIn($user, $token, $request);

        return response()->json(['message' => 'Signed in.'])->withCookie($this->devices->cookie($token));
    }

    private function pendingUser(Request $request): User
    {
        $pending = $request->session()->get(self::PENDING);
        $user = $pending && now()->timestamp - (int) $pending['at'] <= self::PENDING_MINUTES * 60 ? User::find($pending['user']) : null;
        if (! $user || ! $user->isActive() || ! $this->twoFactor->enabled($user)) {
            $request->session()->forget(self::PENDING);
            throw ValidationException::withMessages(['code' => 'Please sign in again.']);
        }

        return $user;
    }
}
