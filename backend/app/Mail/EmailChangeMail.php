<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent to a NEW sign-in email: the change happens only when this link is opened (SECURITY_REPORT M7). */
class EmailChangeMail extends Mailable
{
    use Branded;

    public function __construct(public string $name, public string $email, public string $link, public int $hours, public ?string $by) {}

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope('Confirm your new HotelMS sign-in email');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.email-change', with: $this->brandData());
    }
}
