<?php

namespace App\Services\Notify;

use App\Models\PlatformSetting;
use App\Support\SafeHost;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The mail server can be set in Super Admin → Settings → Emails (Owner only). When it is, it replaces the
 * MAIL_* lines of the .env file; when it is empty, the .env file is used as before.
 * Applied only when an email is about to be sent (no cost for other requests).
 */
class MailSettings
{
    public const KEYS = ['mail_host', 'mail_port', 'mail_encryption', 'mail_username', 'mail_password', 'mail_from_address'];

    public static function apply(): void
    {
        try {
            $s = PlatformSetting::whereIn('key', [...self::KEYS, 'email_from_name'])->pluck('value', 'key');
        } catch (Throwable) {
            return; // e.g. before the first migration
        }
        if (empty($s['mail_host'])) {
            return;
        }
        // Checked again here: a name may point inside the server's network later (SECURITY_REPORT L5). Then the
        // .env mail server is used instead.
        if (! SafeHost::allowed((string) $s['mail_host'])) {
            report(new \RuntimeException('Mail server '.$s['mail_host'].' points inside the server network; using the .env mail settings.'));

            return;
        }
        $password = null;
        if (! empty($s['mail_password'])) {
            try {
                $password = Crypt::decryptString((string) $s['mail_password']);
            } catch (Throwable) {
                $password = null; // the app key changed: type the password again
            }
        }
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $s['mail_host'],
            'mail.mailers.smtp.port' => (int) ($s['mail_port'] ?? 587),
            'mail.mailers.smtp.scheme' => ($s['mail_encryption'] ?? 'tls') === 'ssl' ? 'smtps' : null,
            'mail.mailers.smtp.username' => $s['mail_username'] ?? null,
            'mail.mailers.smtp.password' => $password,
        ]);
        if (! empty($s['mail_from_address'])) {
            config(['mail.from.address' => $s['mail_from_address']]);
        }
        if (! empty($s['email_from_name'])) {
            config(['mail.from.name' => $s['email_from_name']]);
        }
    }
}
