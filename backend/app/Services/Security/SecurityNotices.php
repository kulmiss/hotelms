<?php

namespace App\Services\Security;

use App\Mail\SecurityNoticeMail;
use App\Models\User;
use App\Support\HotelClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Short "Was this you?" emails about a person's own sign-in (SECURITY_REPORT M2, M7).
 * Never from a demo hotel (decision 14), never blocking: a mail problem does not stop the change.
 */
class SecurityNotices
{
    private const IF_NOT = 'If not, change your password now (Forgot password on the sign-in page) and tell your manager or HotelMS support.';

    /** @var array<string, array{0: string, 1: string}> kind => [subject, text] */
    private const KINDS = [
        'locked' => ['Sign-in paused after wrong passwords', 'Someone typed a wrong password for your account many times, so signing in is paused for :minutes minutes.'],
        'password_changed' => ['Your password was changed', 'The password of your account was changed.'],
        'two_factor_on' => ['Two-step sign-in is on', 'Two-step sign-in was turned on for your account.'],
        'two_factor_off' => ['Two-step sign-in was turned off', 'Two-step sign-in was turned off for your account. Signing in now needs only the password.'],
        'recovery_codes' => ['New recovery codes', 'New recovery codes were made for your account. The old ones no longer work.'],
        'new_device' => ['New sign-in to your account', 'Your account was used to sign in on a device it had not used before.'],
        'email_changed' => ['Your sign-in email was changed', 'The sign-in email of your account was changed to :new. This address is no longer used for signing in.'],
    ];

    public function send(User $user, string $kind, ?Request $request = null, array $extra = [], ?string $to = null): void
    {
        if ($user->hotel?->is_demo || ! isset(self::KINDS[$kind])) {
            return;
        }
        [$subject, $text] = self::KINDS[$kind];
        foreach ($extra as $k => $v) {
            $text = str_replace(':'.$k, (string) $v, $text);
        }
        $facts = [['When', HotelClock::local(now(), $user->hotel)->format('d M Y, H:i')]];
        if ($request) {
            $facts[] = ['From address', (string) $request->ip()];
            $facts[] = ['Device', app(Devices::class)->label($request)];
        }
        try {
            Mail::to($to ?? $user->email)->send(new SecurityNoticeMail($user->name, $subject, $text, $facts, self::IF_NOT));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
