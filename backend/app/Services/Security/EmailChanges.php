<?php

namespace App\Services\Security;

use App\Mail\EmailChangeMail;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A new sign-in email takes effect only when the link sent to that address is opened (SECURITY_REPORT M7),
 * and the old address is told. Demo hotels change at once (they never send emails).
 */
class EmailChanges
{
    public const HOURS = 24;

    /** @return 'changed'|'pending'|'same' */
    public function request(User $user, string $email, ?User $by = null): string
    {
        $email = mb_strtolower(trim($email));
        if ($email === mb_strtolower($user->email)) {
            $user->forceFill(['pending_email' => null, 'pending_email_token' => null, 'pending_email_expires_at' => null])->save();

            return 'same';
        }
        if ($user->hotel?->is_demo) {
            $user->forceFill(['email' => $email])->save();

            return 'changed';
        }
        $token = Str::random(48);
        $user->forceFill(['pending_email' => $email, 'pending_email_token' => hash('sha256', $token), 'pending_email_expires_at' => now()->addHours(self::HOURS)])->save();
        $link = rtrim((string) config('app.frontend_url'), '/').'/confirm-email?token='.$token;
        try {
            Mail::to($email)->send(new EmailChangeMail($user->name, $email, $link, self::HOURS, $by && ! $by->is($user) ? $by->name : null));
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['email' => 'The confirmation email could not be sent. Check the address, or try again later.']);
        }

        return 'pending';
    }

    /** Opens the link: the new email is used from now on, and the old address gets a notice. */
    public function confirm(string $token): User
    {
        $user = User::where('pending_email_token', hash('sha256', $token))->where('pending_email_expires_at', '>', now())->first();
        if (! $user || ! $user->pending_email) {
            throw ValidationException::withMessages(['token' => 'This link does not work any more. Ask for the change again.']);
        }
        if (User::withTrashed()->where('email', $user->pending_email)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['token' => 'Another account uses this email now.']);
        }
        $old = $user->email;
        DB::transaction(function () use ($user) {
            $user->forceFill(['email' => $user->pending_email, 'pending_email' => null, 'pending_email_token' => null, 'pending_email_expires_at' => null])->save();
        });
        $log = fn () => app(ActivityLogger::class)->log($user->is_super_admin ? 'platform.email_changed' : 'security.email_changed', "Sign-in email changed from {$old} to {$user->email} (confirmed by the new address)", $user);
        $user->hotel ? app(TenantContext::class)->runAs($user->hotel, $log) : $log();
        app(SecurityNotices::class)->send($user, 'email_changed', null, ['new' => $user->email], $old);
        app(SecurityLog::class)->record('email_changed', $user, null, $user->email, "from {$old}");

        return $user;
    }
}
