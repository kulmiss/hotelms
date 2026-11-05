<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells a Hotel Admin (or the person) about a sign-in from a new device, or one waiting for approval. */
class NewDeviceMail extends Mailable
{
    use Branded;

    public function __construct(public string $person, public string $device, public ?string $ip, public string $time, public bool $waiting, public string $link) {}

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope($this->waiting ? "Device waiting for approval: {$this->person}" : "New sign-in: {$this->person}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.new-device', with: $this->brandData());
    }
}
