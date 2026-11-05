<?php

namespace App\Mail;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HotelAdminWelcome extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $admin,
        public Hotel $hotel,
        public string $temporaryPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Welcome to HotelMS – {$this->hotel->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.hotel-admin-welcome', with: [
            'signInUrl' => rtrim((string) config('app.frontend_url'), '/').'/login',
        ]);
    }
}
