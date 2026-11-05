<?php

namespace App\Mail;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent when the HotelMS team resets someone's password. */
class TemporaryPassword extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Hotel $hotel,
        public string $temporaryPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your new HotelMS password');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.temporary-password', with: [
            'signInUrl' => rtrim((string) config('app.frontend_url'), '/').'/login',
        ]);
    }
}
