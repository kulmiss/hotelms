<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\ActivityLogger;
use App\Services\Notify\MailSettings;
use App\Support\SafeHost;
use App\Support\SafeImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class PlatformSettingsController extends Controller
{
    /** Settings only the Owner may change (besides the mail server). */
    private const OWNER_ONLY = ['platform_name', 'brand_color', 'login_message', 'email_from_name', 'email_footer', 'telegram_bot_token', 'telegram_bot_username', 'two_factor_admins'];

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->present()]);
    }

    public function update(Request $request, ActivityLogger $log): JsonResponse
    {
        $data = $request->validate([
            'platform_name' => ['sometimes', 'required', 'string', 'max:40'],
            'support_email' => ['sometimes', 'nullable', 'email', 'max:120'],
            'support_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'demo_public' => ['sometimes', 'boolean'],
            'demo_minutes' => ['sometimes', 'integer', 'between:5,480'],
            'two_factor_admins' => ['sometimes', 'boolean'],
            'default_trial_days' => ['sometimes', 'integer', 'between:0,365'],
            'default_trial_plan_id' => ['sometimes', 'nullable', 'exists:plans,id'],
            'grace_days' => ['sometimes', 'integer', 'between:0,30'],
            'brand_color' => ['sometimes', 'required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'login_message' => ['sometimes', 'nullable', 'string', 'max:200'],
            'email_from_name' => ['sometimes', 'required', 'string', 'max:60'],
            'email_footer' => ['sometimes', 'nullable', 'string', 'max:300'],
            'telegram_bot_token' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^(\d+:[A-Za-z0-9_-]{20,}|•+.*)$/'],
            'telegram_bot_username' => ['sometimes', 'nullable', 'string', 'max:60', 'regex:/^@?[A-Za-z0-9_]{3,}$/'],
            'mail_host' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9.\-]+$/',
                fn ($attr, $value, $fail) => $value && ! SafeHost::allowed((string) $value) ? $fail('Use the mail server\'s internet address (not localhost or an address inside the server\'s network).') : null],
            'mail_port' => ['sometimes', 'integer', 'between:1,65535'],
            'mail_encryption' => ['sometimes', 'in:tls,ssl,none'],
            'mail_username' => ['sometimes', 'nullable', 'string', 'max:120'],
            'mail_password' => ['sometimes', 'nullable', 'string', 'max:200'],
            'mail_from_address' => ['sometimes', 'nullable', 'email', 'max:120'],
        ]);
        // The mail server decides where every system email comes from: the Owner only.
        if (array_intersect_key($data, array_flip(MailSettings::KEYS)) && $request->user()->platform_role !== 'owner') {
            abort(403, 'Only the Owner can change the mail server.');
        }
        // Branding, the Telegram bot (it receives hotels' messages) and two-step sign-in for admins: the Owner only too.
        if (array_intersect_key($data, array_flip(self::OWNER_ONLY)) && $request->user()->platform_role !== 'owner') {
            abort(403, 'Only the Owner can change branding, Telegram and two-step sign-in.');
        }
        // The password is shown masked; sending the masked value back keeps the saved one. Saved encrypted.
        if (array_key_exists('mail_password', $data)) {
            if (is_string($data['mail_password']) && str_starts_with($data['mail_password'], '•')) {
                unset($data['mail_password']);
            } elseif (is_string($data['mail_password']) && $data['mail_password'] !== '') {
                $data['mail_password'] = Crypt::encryptString($data['mail_password']);
            } else {
                $data['mail_password'] = null;
            }
        }
        // The token is shown masked; sending the masked value back keeps the saved one.
        if (array_key_exists('telegram_bot_token', $data) && is_string($data['telegram_bot_token']) && str_starts_with($data['telegram_bot_token'], '•')) {
            unset($data['telegram_bot_token']);
        } elseif (array_key_exists('telegram_bot_token', $data)) {
            // Saved encrypted like the mail password (SECURITY_REPORT M6).
            $data['telegram_bot_token'] = is_string($data['telegram_bot_token']) && $data['telegram_bot_token'] !== '' ? Crypt::encryptString($data['telegram_bot_token']) : null;
        }
        if (! empty($data['telegram_bot_username'])) {
            $data['telegram_bot_username'] = ltrim($data['telegram_bot_username'], '@');
        }

        $before = PlatformSetting::allValues();
        foreach ($data as $key => $value) {
            PlatformSetting::write($key, $value);
        }
        $changed = array_keys(array_diff_assoc(array_map('json_encode', $data), array_map('json_encode', array_intersect_key($before, $data))));
        if ($changed) {
            $hide = fn (array $v) => array_merge($v, array_intersect_key(['telegram_bot_token' => '(secret)', 'mail_password' => '(secret)'], $v));
            $log->log('platform.settings', 'Platform settings changed: '.implode(', ', $changed), null, [
                'old' => $hide(array_intersect_key($before, array_flip($changed))),
                'new' => $hide(array_intersect_key($data, array_flip($changed))),
            ]);
        }

        return response()->json(['data' => $this->present()]);
    }

    /** Owner: send a test email to yourself with the saved mail server; says what went wrong if it fails. */
    public function testEmail(Request $request): JsonResponse
    {
        abort_unless($request->user()->platform_role === 'owner', 403, 'Only the Owner can test the mail server.');
        $to = $request->validate(['to' => ['nullable', 'email:rfc', 'max:190']])['to'] ?? $request->user()->email;
        $host = PlatformSetting::read('mail_host');
        if ($host && ! SafeHost::allowed((string) $host)) { // checked again now: a name can point somewhere else later
            throw ValidationException::withMessages(['mail_host' => 'Use the mail server\'s internet address (not localhost or an address inside the server\'s network).']);
        }
        MailSettings::apply();
        try {
            app('mail.manager')->forgetMailers();
            Mail::raw('This is a test email from '.PlatformSetting::read('platform_name').'. The mail server works.', fn ($m) => $m->to($to)->subject('Test email'));
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['mail_host' => 'Sending failed: '.mb_substr($e->getMessage(), 0, 300)]);
        }

        return response()->json(['message' => 'Sent to '.$to.'. Check the inbox (and spam).']);
    }

    /** Upload or remove the logo shown in the sidebar, sign-in page and emails. */
    public function logo(Request $request): JsonResponse
    {
        abort_unless($request->user()->platform_role === 'owner', 403, 'Only the Owner can change branding, Telegram and two-step sign-in.');
        $request->validate(['logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024']]);

        $old = PlatformSetting::read('brand_logo_path');
        if ($old) {
            Storage::disk('public')->delete($old);
        }

        $path = null;
        if ($file = $request->file('logo')) {
            [$bytes] = SafeImage::clean($file); // drawn again: no hidden data (SECURITY_REPORT L4)
            $path = 'branding/'.$file->hashName();
            Storage::disk('public')->put($path, $bytes);
        }
        PlatformSetting::write('brand_logo_path', $path);

        return response()->json(['data' => $this->present()]);
    }

    /** @return array<string, mixed> */
    private function present(): array
    {
        $values = PlatformSetting::allValues();
        $values['brand_logo_url'] = self::logoUrl($values['brand_logo_path'] ?? null);
        $token = PlatformSetting::secret('telegram_bot_token');
        $values['telegram_bot_token'] = $token ? '••••••'.substr((string) $token, -4) : null; // never sent back in full
        $values['mail_password'] = ! empty($values['mail_password']) ? '••••••••' : null; // never sent back

        return $values;
    }

    public static function logoUrl(?string $path): ?string
    {
        return $path ? '/storage/'.ltrim($path, '/') : null;
    }
}
