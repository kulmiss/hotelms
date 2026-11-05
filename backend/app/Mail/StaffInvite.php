<?php

namespace App\Mail;

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
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Hotel $hotel,
        public string $temporaryPassword,
        public bool $isReset = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->isReset
            ? 'Your new HotelMS password'
            : "Your HotelMS account at {$this->hotel->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.staff-invite', with: [
            'signInUrl' => rtrim((string) config('app.frontend_url'), '/').'/login',
        ]);
    }
}
