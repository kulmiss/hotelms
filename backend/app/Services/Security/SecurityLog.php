<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The security event log (SECURITY_REPORT M9): who signed in, failed, was paused, changed a password, an email or
 * two-step sign-in, was refused (403) or slowed down (429). Kept 1 year (pruned daily). Never breaks a request,
 * and one person (or address) adds at most 60 rows a minute, so a flood cannot fill the disk.
 * Hotel Admins see their hotel's rows (Activity log → Security); the HotelMS team sees all (Watchdog).
 */
class SecurityLog
{
    public const KEEP_DAYS = 365;

    private const PER_MINUTE = 60;

    /** Plain-English labels, also the list of kinds the screens can filter by. */
    public const KINDS = [
        'login_ok' => 'Signed in',
        'login_failed' => 'Wrong password',
        'login_locked' => 'Sign-in paused (too many wrong passwords)',
        'two_factor_failed' => 'Wrong two-step code',
        'pin_failed' => 'Wrong PIN',
        'pin_switch' => 'Took over a device with a PIN',
        'password_changed' => 'Password changed',
        'password_reset' => 'Password set with a reset link',
        'email_change_requested' => 'Sign-in email change asked',
        'email_changed' => 'Sign-in email changed',
        'two_factor_on' => 'Two-step sign-in turned on',
        'two_factor_off' => 'Two-step sign-in turned off',
        'recovery_codes' => 'New recovery codes',
        'recovery_code_used' => 'Signed in with a recovery code',
        'devices_signed_out' => 'Other devices signed out',
        'session_expired' => 'Sign-in ended after 12 hours',
        'denied' => 'Refused (no permission)',
        'throttled' => 'Too many requests',
        'invoice_voided' => 'Bill voided',
        'refund' => 'Refund given',
    ];

    public function record(string $kind, ?User $user = null, ?Request $request = null, ?string $email = null, ?string $detail = null): void
    {
        try {
            $request ??= request();
            $who = $user?->id ? 'u'.$user->id : 'ip'.($request?->ip() ?? '-');
            $key = "seclog:{$who}:".now()->format('YmdHi');
            Cache::add($key, 0, 120);
            if (Cache::increment($key) > self::PER_MINUTE) {
                return;
            }
            DB::table('security_events')->insert([
                'kind' => mb_substr($kind, 0, 40),
                'user_id' => $user?->id,
                'hotel_id' => $user?->hotel_id,
                'email' => $email !== null ? mb_substr(mb_strtolower($email), 0, 190) : null,
                'ip' => $request?->ip(),
                'device' => $request ? mb_substr(app(Devices::class)->label($request), 0, 80) : null,
                'detail' => $detail !== null ? mb_substr($detail, 0, 300) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // the log must never break the app
        }
    }

    public function prune(): int
    {
        return DB::table('security_events')->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }
}
