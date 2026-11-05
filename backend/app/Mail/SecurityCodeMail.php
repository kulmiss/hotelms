<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The 6-digit sign-in code for two-step sign-in by email. Sent at once (never queued). */
class SecurityCodeMail extends Mailable
{
    use Branded;

    public function __construct(public string $name, public string $code, public int $minutes) {}

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope("Your HotelMS sign-in code: {$this->code}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.security-code', with: $this->brandData());
    }
}
