<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A new hotel's owner confirms their email before the first sign-in (owner, 14 Nov 2026). */
class SignupVerifyMail extends Mailable
{
    use Branded;

    public function __construct(public string $name, public string $hotel, public string $link, public int $days) {}

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope('Confirm your email for HotelMS');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.signup-verify', with: $this->brandData());
    }
}
