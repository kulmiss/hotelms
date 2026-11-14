<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Platform-wide settings edited by the Super Admin (e.g. default trial length).
 */
#[Fillable(['key', 'value'])]
class PlatformSetting extends Model
{
    public const DEFAULTS = [
        // General
        'platform_name' => 'HotelMS',
        'support_email' => null,
        // Sign-up (owner, 14 Nov 2026): off / approval / open; a free trial of a bigger plan only when switched on.
        'signup_mode' => 'off',
        'signup_trial' => false,
        'support_phone' => null,
        'whatsapp' => null, // "Upgrade" and "Contact us" (landing page, plan limits), e.g. +2519…
        'demo_public' => false,
        'demo_minutes' => 30, // how long one "Try the demo" visit lasts
        // Trials and billing
        'default_trial_days' => 30,
        'default_trial_plan_id' => null,
        'grace_days' => 3,
        // Branding (applies to the whole web app)
        'brand_color' => '#0f766e',
        'brand_logo_path' => null,
        'login_message' => null,
        // Emails
        'email_from_name' => 'HotelMS',
        'email_footer' => null,
        // Mail server (Owner only; empty = the MAIL_* lines of .env). The password is stored encrypted.
        'mail_host' => null,
        'mail_port' => 587,
        'mail_encryption' => 'tls',
        'mail_username' => null,
        'mail_password' => null,
        'mail_from_address' => null,
        // Security: two-step sign-in required for the HotelMS team and Hotel Admins
        'two_factor_admins' => true,
        // Telegram bot for reports and alerts (free; create it with @BotFather)
        'telegram_bot_token' => null,
        'telegram_bot_username' => null,
        // Content (Super Admin → Content): on HotelMS invoices, in the welcome email, and defaults for new hotels
        'billing_name' => null,
        'billing_tin' => null,
        'billing_address' => null,
        'payment_instructions' => null,
        'welcome_message' => null,
        'new_hotel_defaults' => ['deposit_value' => '30', 'vat_rate' => '15.00', 'invoice_footer' => null, 'receipt_paper' => 'a4'],
    ];

    /** Settings anyone may read (shown on the sign-in page before login). */
    public const PUBLIC = ['platform_name', 'brand_color', 'login_message', 'support_email', 'support_phone', 'whatsapp', 'demo_public', 'demo_minutes', 'signup_mode'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function read(string $key): mixed
    {
        $row = static::where('key', $key)->first();
        if (! $row && $key === 'two_factor_admins') {
            return (bool) config('hotelms.two_factor_admins');
        }

        return $row ? $row->value : (self::DEFAULTS[$key] ?? null);
    }

    public static function write(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Settings that are secrets: stored encrypted with APP_KEY, never sent back in full (SECURITY_REPORT M6). */
    public const SECRETS = ['telegram_bot_token', 'mail_password'];

    /** A secret setting in plain text (a value saved before it was encrypted is returned as it is). */
    public static function secret(string $key): ?string
    {
        $v = static::read($key);
        if (! is_string($v) || $v === '') {
            return null;
        }
        try {
            return Crypt::decryptString($v);
        } catch (DecryptException) {
            return $v;
        }
    }

    /** @return array<string, mixed> */
    public static function allValues(): array
    {
        $saved = static::pluck('value', 'key')->all();

        return array_merge(self::DEFAULTS, ['two_factor_admins' => (bool) config('hotelms.two_factor_admins')], array_intersect_key($saved, self::DEFAULTS));
    }
}
