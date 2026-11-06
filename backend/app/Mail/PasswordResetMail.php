<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordResetMail extends Mailable
{
    use Branded;

    public function __construct(public string $name, public string $link) {}

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope('Set a new password');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.password-reset', with: $this->brandData());
    }
}
