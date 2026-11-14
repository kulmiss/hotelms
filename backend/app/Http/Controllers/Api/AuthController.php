<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceSessionRules;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Impersonation;
use App\Services\Modules\Attendance;
use App\Services\Security\Devices;
use App\Services\Security\EmailChanges;
use App\Services\Security\SecurityLog;
use App\Services\Security\SecurityNotices;
use App\Services\Security\TwoFactor;
use App\Services\Watchdog\WatchEvents;
use App\Support\PasswordRules;
use Illuminate\Http\Exceptions\HttpResponseException;
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

    /** Session key: the password used at sign-in is weaker than today's rules (shown as a note). */
    public const WEAK = 'password.weak';

    private const FAILS = 'login-fails|';

    private const LOCK = 'login-lock|';

    private const LOCKS_TODAY = 'login-locks|';

    /** "Remember me" for staff lasts at most this long (owner Q3). */
    private const REMEMBER_DAYS = 30;

    /** A bcrypt hash of a random text: checked when the email has no account, so both answers take as long. */
    private const DUMMY_HASH = '$2y$12$9RM3L172I0KbTCFkkUXT6uvh4GkqX.pXACNSX3Q0TUx.NbIcRWapq';

    public function __construct(private Devices $devices, private TwoFactor $twoFactor) {}

    /** 10 wrong passwords in an hour pause this email: 15, 30, then 60 minutes; the person is emailed. */
    private function countFailure(Request $request, string $email, ?User $user): void
    {
        RateLimiter::hit(self::FAILS.$email, 3600);
        if (RateLimiter::attempts(self::FAILS.$email) < 10) {
            return;
        }
        RateLimiter::clear(self::FAILS.$email);
        $locks = RateLimiter::hit(self::LOCKS_TODAY.$email, 86400);
        $minutes = min(60, 15 * 2 ** ($locks - 1));
        RateLimiter::hit(self::LOCK.$email, $minutes * 60);
        app(WatchEvents::class)->record('login_locked', $request, 429, $email);
        app(SecurityLog::class)->record('login_locked', $user, $request, $email, "{$minutes} minutes");
        if ($user) {
            app(SecurityNotices::class)->send($user, 'locked', $request, ['minutes' => $minutes]);
        }
    }

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

        $email = mb_strtolower($credentials['email']);
        // Per account, from every address (SECURITY_REPORT M2): after 10 wrong passwords, signing in to this
        // email pauses 15 minutes, then 30, then 60. The same for emails without an account, so the
        // answer never tells whether an account exists.
        if (RateLimiter::tooManyAttempts(self::LOCK.$email, 1)) {
            $minutes = (int) ceil(RateLimiter::availableIn(self::LOCK.$email) / 60);
            throw ValidationException::withMessages(['email' => "Too many wrong passwords for this email. Try again in {$minutes} minutes, or use Forgot password."]);
        }

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Auth::guard('web')->validate(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            if (! $user) {
                Hash::check($credentials['password'], self::DUMMY_HASH); // as slow as a real check (SECURITY_REPORT L1)
            }
            app(WatchEvents::class)->record('login_failed', $request, 422, $email);
            app(SecurityLog::class)->record('login_failed', $user, $request, $email);
            $this->countFailure($request, $email, $user);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        RateLimiter::clear(self::FAILS.$email);
        // A password from before the stricter rules (14 Nov 2026) keeps working; the app asks to change it.
        $request->session()->put(self::WEAK, PasswordRules::isWeak($credentials['password'], $user->email));

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'This account is disabled. Please contact your hotel admin.']);
        }
        // A hotel that registered itself (owner, 14 Nov 2026): email confirmed first, then (in Approval mode) approved.
        if ($user->hotel?->signupLocked()) {
            $this->signupLocked($user);
        }
        if ($user->temporaryPasswordExpired()) {
            throw ValidationException::withMessages(['email' => 'This temporary password has expired (it works for 7 days). Ask your manager to send you a new one.']);
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

    private function signupLocked(User $user): never
    {
        [$code, $message] = match (true) {
            $user->hotel->signup_status === 'rejected' => ['signup_rejected', 'This sign-up was not approved. Check your email, or contact us.'],
            $user->email_verified_at === null => ['email_unverified', 'Please confirm your email first: open the link we sent you.'],
            default => ['signup_pending', 'Your hotel is waiting for approval. We will email you, usually within one working day.'],
        };

        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code, 'errors' => ['email' => [$message]]], 422));
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
            app(SecurityLog::class)->record('two_factor_failed', $user, $request);
            throw ValidationException::withMessages(['code' => 'That code is not right, or it expired.']);
        }
        RateLimiter::clear($key);
        $pending = $request->session()->pull(self::PENDING);
        if (str_contains($data['code'], '-')) {
            app(ActivityLogger::class)->log('security.recovery_code_used', "{$user->name} signed in with a recovery code", $user);
            app(SecurityLog::class)->record('recovery_code_used', $user, $request);
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
            app(SecurityLog::class)->record('pin_failed', $current, $request, null, 'screen lock');
            throw ValidationException::withMessages(['pin' => 'This PIN cannot be used here. Sign in with your password.']);
        }
        if ($user->id === $current->id) {
            $request->session()->forget(EnforceSessionRules::LOCKED);

            return response()->json(['message' => 'Unlocked.']);
        }
        // A PIN a manager set (for the attendance kiosk) never lets anyone take over the device as that person.
        if (! Attendance::ownPin($user)) {
            throw ValidationException::withMessages(['pin' => 'This PIN was set by a manager. Set your own PIN in My profile to use it here.']);
        }
        $token = $this->devices->token($request);
        $this->devices->check($user, $token, $request);
        app(ActivityLogger::class)->log('security.pin_switch', "{$user->name} took over the device from {$current->name}", $user);
        $request->session()->forget(self::WEAK); // the note was about the other person's password
        app(SecurityLog::class)->record('pin_switch', $user, $request, null, "took over the device from {$current->name}");

        return $this->complete($request, $user, false, $token);
    }

    /** Opens the link sent to a new sign-in email (SECURITY_REPORT M7). No sign-in needed: the link is the proof. */
    public function confirmEmail(Request $request, EmailChanges $changes): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:48']]);
        $user = $changes->confirm($data['token']);

        return response()->json(['message' => 'Your sign-in email is now '.$user->email.'.', 'email' => $user->email]);
    }

    /** The app locked the screen (idle or "Lock screen"): from now on the server answers only the lock screen. */
    public function lock(Request $request): JsonResponse
    {
        if (! $request->session()->has(Impersonation::SESSION_KEY)) {
            $request->session()->put(EnforceSessionRules::LOCKED, true);
        }

        return response()->json(['message' => 'Locked.']);
    }

    /** Unlock your own locked screen with your password (anyone, including admins). */
    public function unlock(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'That password is not right.']);
        }
        $request->session()->forget(EnforceSessionRules::LOCKED);

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
        // "Remember me" (owner Q3): at most 30 days, and never for Super Admins and Hotel Admins.
        $remember = $remember && ! $user->is_super_admin && ! $user->isHotelAdmin();
        Auth::guard('web')->setRememberDuration(self::REMEMBER_DAYS * 24 * 60);
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put(EnforceSessionRules::SIGNED_IN_AT, now()->timestamp);
        $request->session()->put(EnforceSessionRules::REMEMBERED, $remember);
        $request->session()->forget(EnforceSessionRules::LOCKED);
        $request->session()->forget(self::PENDING);
        // The session belongs to this person now (also after a PIN switch from someone else).
        $request->session()->put('password_hash_web', $user->getAuthPassword());
        $user->forceFill(['last_login_at' => now()])->save();
        $this->devices->signedIn($user, $token, $request);
        app(SecurityLog::class)->record('login_ok', $user, $request);

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
