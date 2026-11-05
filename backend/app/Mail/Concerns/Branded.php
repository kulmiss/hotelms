<?php

namespace App\Mail\Concerns;

use App\Models\PlatformSetting;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/** Sender name, platform name and footer from Settings → Branding / Emails. */
trait Branded
{
    protected function brandedEnvelope(string $subject): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), (string) PlatformSetting::read('email_from_name')),
            subject: str_replace('HotelMS', (string) PlatformSetting::read('platform_name'), $subject),
        );
    }

    /** @return array<string, mixed> */
    protected function brandData(): array
    {
        return [
            'platformName' => PlatformSetting::read('platform_name'),
            'emailFooter' => PlatformSetting::read('email_footer'),
            'signInUrl' => rtrim((string) config('app.frontend_url'), '/').'/login',
        ];
    }
}
