<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A new staff account (or a new temporary password) for a hotel. */
class StaffInvite extends Mailable
{
    use Branded, Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Hotel $hotel,
        public string $temporaryPassword,
        public bool $isReset = false,
    ) {}

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope($this->isReset
            ? 'Your new password'
            : "Your account at {$this->hotel->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.staff-invite', with: $this->brandData());
    }
}
