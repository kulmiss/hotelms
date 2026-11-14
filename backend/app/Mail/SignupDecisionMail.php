<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Tells a new hotel that its sign-up was approved, or refused with the reason. */
class SignupDecisionMail extends Mailable
{
    use Branded;

    public function __construct(public string $name, public string $hotel, public bool $approved, public ?string $reason) {}

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope($this->approved ? 'Your hotel is ready on HotelMS' : 'About your HotelMS sign-up');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.signup-decision', with: $this->brandData());
    }
}
