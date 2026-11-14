<?php

namespace App\Services\Signup;

use App\Mail\SignupDecisionMail;
use App\Mail\SignupVerifyMail;
use App\Models\Branch;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\DefaultRoles;
use App\Services\HotelDefaults;
use App\Services\HotelStatus;
use App\Services\Notify\Notifier;
use App\Services\Security\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Hotels register themselves on the Free plan (owner, 14 Nov 2026).
 * Super Admin → Settings → Sign-up: off (no sign-up) / approval (locked until approved) / open (works after the email is confirmed).
 * The email is always confirmed before the first sign-in. Nothing is sent to anyone else.
 */
class SelfSignup
{
    /** Days a confirmation link works; unconfirmed sign-ups are removed after this (P3). */
    public const VERIFY_DAYS = 7;

    public function __construct(private DefaultRoles $roles, private HotelDefaults $defaults, private HotelStatus $status) {}

    public static function mode(): string
    {
        $mode = (string) PlatformSetting::read('signup_mode');

        return in_array($mode, ['approval', 'open'], true) ? $mode : 'off';
    }

    /**
     * @param  array{hotel_name: string, owner_name: string, email: string, phone: string, city: string, rooms: int, password: string}  $data
     */
    public function register(array $data, Request $request): Hotel
    {
        $mode = self::mode();
        if ($mode === 'off') {
            throw ValidationException::withMessages(['email' => 'Sign-up is closed right now. Please contact us.']);
        }
        $free = Plan::where('code', 'free')->where('is_active', true)->first();
        if (! $free) {
            throw ValidationException::withMessages(['email' => 'Sign-up is not ready yet. Please contact us.']);
        }
        $trial = (bool) PlatformSetting::read('signup_trial');
        $trialDays = (int) PlatformSetting::read('default_trial_days');
        $trialPlan = PlatformSetting::read('default_trial_plan_id');
        $token = Str::random(48);
        $email = mb_strtolower(trim($data['email']));

        [$hotel, $admin] = DB::transaction(function () use ($data, $email, $mode, $free, $trial, $trialDays, $trialPlan, $token, $request) {
            $hotel = Hotel::create([
                'name' => $data['hotel_name'], 'email' => $email, 'phone' => $data['phone'], 'city' => $data['city'],
                'plan_id' => $free->id, 'status' => 'active', 'billing_cycle' => 'monthly',
                'trial_plan_id' => $trial && $trialPlan && $trialDays > 0 ? $trialPlan : null,
                'trial_ends_at' => $trial && $trialPlan && $trialDays > 0 ? now()->addDays($trialDays)->toDateString() : null,
                'signup_status' => 'unverified',
                'signup' => [
                    'owner_name' => $data['owner_name'], 'rooms' => (int) $data['rooms'], 'mode' => $mode,
                    'terms_accepted_at' => now()->toIso8601String(), 'ip' => $request->ip(),
                ],
            ]);
            $this->status->refresh($hotel);
            $branch = Branch::create(['hotel_id' => $hotel->id, 'name' => 'Main branch', 'city' => $data['city'], 'phone' => $data['phone'], 'email' => $email]);
            $roles = $this->roles->ensureFor($hotel);
            $this->defaults->apply($hotel);
            $admin = User::create([
                'hotel_id' => $hotel->id, 'role_id' => $roles['hotel_admin']->id, 'name' => $data['owner_name'], 'email' => $email,
                'phone' => $data['phone'], 'password' => $data['password'], 'status' => 'active', 'must_change_password' => false,
            ]);
            $admin->forceFill(['email_verify_token' => hash('sha256', $token)])->save();
            $admin->branches()->attach($branch);

            return [$hotel, $admin];
        });

        app(SecurityLog::class)->record('signup', $admin, $request, $email, "{$hotel->name} ({$mode})");
        $this->sendVerify($admin, $token);

        return $hotel->fresh();
    }

    /** Sends a new confirmation link. Always quiet: never tells whether the email has an account. */
    public function resend(string $email): void
    {
        $user = User::where('email', mb_strtolower(trim($email)))->whereNull('email_verified_at')->whereNotNull('email_verify_token')->first();
        if (! $user || $user->hotel?->signup_status !== 'unverified') {
            return;
        }
        $token = Str::random(48);
        $user->forceFill(['email_verify_token' => hash('sha256', $token)])->save();
        $this->sendVerify($user, $token);
    }

    /** Opens the link. @return 'pending'|'approved' */
    public function verify(string $token, Request $request): string
    {
        $user = User::where('email_verify_token', hash('sha256', $token))->whereNull('email_verified_at')->first();
        $hotel = $user?->hotel;
        if (! $user || ! $hotel || $hotel->signup_status !== 'unverified' || $hotel->created_at->lt(now()->subDays(self::VERIFY_DAYS))) {
            throw ValidationException::withMessages(['token' => 'This link does not work any more. Sign in to get a new one, or register again.']);
        }
        $next = ($hotel->signup['mode'] ?? 'approval') === 'open' ? 'approved' : 'pending';
        DB::transaction(function () use ($user, $hotel, $next) {
            $user->forceFill(['email_verified_at' => now(), 'email_verify_token' => null])->save();
            $hotel->forceFill(['signup_status' => $next] + ($next === 'approved' ? ['signup_decided_at' => now()] : []))->save();
        });
        app(SecurityLog::class)->record('signup_verified', $user, $request);
        if ($next === 'pending') {
            app(Notifier::class)->platform("New hotel waiting for approval: {$hotel->name} ({$hotel->city}).", 'hotels.create');
        }

        return $next;
    }

    public function approve(Hotel $hotel, User $by): Hotel
    {
        if ($hotel->signup_status !== 'pending') {
            throw ValidationException::withMessages(['hotel' => 'This hotel is not waiting for approval.']);
        }
        $hotel->forceFill(['signup_status' => 'approved', 'signup_decided_at' => now(), 'signup_decided_by' => $by->id, 'signup_reason' => null])->save();
        $this->decided($hotel, $by, true, null);

        return $hotel;
    }

    public function reject(Hotel $hotel, User $by, string $reason): Hotel
    {
        if (! in_array($hotel->signup_status, ['pending', 'unverified'], true)) {
            throw ValidationException::withMessages(['hotel' => 'This hotel is not waiting for approval.']);
        }
        $hotel->forceFill(['signup_status' => 'rejected', 'signup_decided_at' => now(), 'signup_decided_by' => $by->id, 'signup_reason' => $reason])->save();
        $this->decided($hotel, $by, false, $reason);

        return $hotel;
    }

    private function decided(Hotel $hotel, User $by, bool $approved, ?string $reason): void
    {
        $owner = User::where('hotel_id', $hotel->id)->orderBy('id')->first();
        app(SecurityLog::class)->record($approved ? 'signup_approved' : 'signup_rejected', $owner, null, $owner?->email, $reason);
        app(ActivityLogger::class)->log($approved ? 'hotel.signup_approved' : 'hotel.signup_rejected', ($approved ? 'Approved the sign-up of ' : 'Refused the sign-up of ').$hotel->name.($reason ? ": {$reason}" : ''), $hotel);
        if ($owner) {
            try {
                Mail::to($owner->email)->send(new SignupDecisionMail($owner->name, $hotel->name, $approved, $reason));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function sendVerify(User $user, string $token): void
    {
        $link = rtrim((string) config('app.frontend_url'), '/').'/verify-email?token='.$token;
        try {
            Mail::to($user->email)->send(new SignupVerifyMail($user->name, $user->hotel?->name ?? '', $link, self::VERIFY_DAYS));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
