<?php

namespace App\Services\Security;

use App\Mail\SecurityCodeMail;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\HotelSettings;
use App\Support\Tenancy\TenantContext;
use App\Support\Totp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Two-step sign-in (decisions.md W7): a code from an authenticator app or by email, no SMS.
 * Required by default for the Super Admin team and Hotel Admins (a platform switch);
 * a Hotel Admin can also require it for all their staff.
 */
class TwoFactor
{
    public const METHODS = ['app', 'email'];

    public const EMAIL_CODE_MINUTES = 10;

    public function enabled(User $user): bool
    {
        return $user->two_factor_method !== null && $user->two_factor_confirmed_at !== null;
    }

    public function required(User $user): bool
    {
        if ($user->hotel?->is_demo) {
            return false;
        }
        // The HotelMS team always (owner Q1, 14 Nov – SECURITY_REPORT L7); Hotel Admins by the Owner's switch (on by default).
        if ($user->is_super_admin) {
            return (bool) config('hotelms.two_factor_team'); // no switch in the app; only development / tests turn it off in .env
        }
        if ($user->isHotelAdmin()) {
            return (bool) PlatformSetting::read('two_factor_admins');
        }
        if ($user->hotel) {
            return (bool) app(TenantContext::class)->runAs($user->hotel, fn () => app(HotelSettings::class)->group(HotelSettings::SECURITY_DEFAULTS)['require_two_factor_staff']);
        }

        return false;
    }

    /** Start setting up: a new secret for the app, or an email code. Nothing changes until confirmed. */
    public function begin(User $user, string $method): array
    {
        if ($method === 'app') {
            $secret = Totp::newSecret();
            Cache::put($this->setupKey($user), Crypt::encryptString($secret), now()->addMinutes(15));
            $issuer = (string) PlatformSetting::read('platform_name');

            return ['method' => 'app', 'secret' => $secret, 'uri' => Totp::uri($secret, $user->email, $issuer)];
        }
        Cache::put($this->setupKey($user), 'email', now()->addMinutes(15));
        $this->sendEmailCode($user);

        return ['method' => 'email', 'email' => $this->maskEmail($user->email)];
    }

    /** Finish setting up with a code. Returns fresh recovery codes (shown once), or null if the code is wrong. */
    public function confirm(User $user, string $code): ?array
    {
        $pending = Cache::get($this->setupKey($user));
        if (! $pending) {
            return null;
        }
        if ($pending === 'email') {
            if (! $this->checkEmailCode($user, $code)) {
                return null;
            }
            $user->forceFill(['two_factor_method' => 'email', 'two_factor_secret' => null]);
        } else {
            $secret = Crypt::decryptString($pending);
            $step = Totp::match($secret, $code);
            if ($step === null) {
                return null;
            }
            Cache::put($this->lastStepKey($user), $step, now()->addMinutes(5));
            $user->forceFill(['two_factor_method' => 'app', 'two_factor_secret' => Crypt::encryptString($secret)]);
        }
        Cache::forget($this->setupKey($user));
        $codes = $this->newRecoveryCodes($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_method' => null, 'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
    }

    /** Check a sign-in code (app or email) or a one-time recovery code. */
    public function verify(User $user, string $code): bool
    {
        $code = trim($code);
        if (str_contains($code, '-')) {
            return $this->useRecoveryCode($user, $code);
        }
        if ($user->two_factor_method === 'email') {
            return $this->checkEmailCode($user, $code);
        }
        $step = Totp::match(Crypt::decryptString((string) $user->two_factor_secret), $code);
        if ($step === null || $step <= (int) Cache::get($this->lastStepKey($user), 0)) {
            return false; // wrong, or already used
        }
        Cache::put($this->lastStepKey($user), $step, now()->addMinutes(5));

        return true;
    }

    public function sendEmailCode(User $user): void
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        Cache::put($this->emailKey($user), ['hash' => Hash::make($code), 'tries' => 0], now()->addMinutes(self::EMAIL_CODE_MINUTES));
        Mail::to($user->email)->send(new SecurityCodeMail($user->name, $code, self::EMAIL_CODE_MINUTES));
    }

    /** @return list<string> */
    public function newRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, 8))->map(fn () => strtolower(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill(['two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_map(fn ($c) => hash('sha256', $c), $codes)))])->save();

        return $codes;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return $user->two_factor_recovery_codes ? count(json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true) ?: []) : 0;
    }

    public function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 2).str_repeat('•', max(1, mb_strlen($name) - 2)).'@'.$domain;
    }

    private function checkEmailCode(User $user, string $code): bool
    {
        $entry = Cache::get($this->emailKey($user));
        if (! $entry || $entry['tries'] >= 5) {
            return false; // expired, or too many wrong tries: send a new code
        }
        if (! Hash::check(preg_replace('/\D+/', '', $code), $entry['hash'])) {
            Cache::put($this->emailKey($user), ['hash' => $entry['hash'], 'tries' => $entry['tries'] + 1], now()->addMinutes(self::EMAIL_CODE_MINUTES));

            return false;
        }
        Cache::forget($this->emailKey($user));

        return true;
    }

    private function useRecoveryCode(User $user, string $code): bool
    {
        if (! $user->two_factor_recovery_codes) {
            return false;
        }
        $hashes = json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true) ?: [];
        $hash = hash('sha256', strtolower($code));
        $left = array_values(array_filter($hashes, fn ($h) => ! hash_equals($h, $hash)));
        if (count($left) === count($hashes)) {
            return false;
        }
        $user->forceFill(['two_factor_recovery_codes' => Crypt::encryptString(json_encode($left))])->save();

        return true;
    }

    private function setupKey(User $user): string
    {
        return "2fa:setup:{$user->id}";
    }

    private function emailKey(User $user): string
    {
        return "2fa:email:{$user->id}";
    }

    private function lastStepKey(User $user): string
    {
        return "2fa:step:{$user->id}";
    }
}
