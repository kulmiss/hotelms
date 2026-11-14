<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Was this you?" – a short note about a change to the person's sign-in (SECURITY_REPORT M2, M7). */
class SecurityNoticeMail extends Mailable
{
    use Branded;

    /** @param list<array{0: string, 1: string}> $facts */
    public function __construct(public string $name, public string $title, public string $text, public array $facts, public string $advice) {}

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope($this->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.security-notice', with: $this->brandData());
    }
}
