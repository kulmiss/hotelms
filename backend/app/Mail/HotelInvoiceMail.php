<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use App\Models\HotelInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The subscription invoice, emailed to the hotel. */
class HotelInvoiceMail extends Mailable
{
    use Branded, Queueable, SerializesModels;

    public function __construct(public HotelInvoice $invoice) {}

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope("Invoice {$this->invoice->number} · {$this->invoice->hotel->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.hotel-invoice', with: $this->brandData() + [
            'link' => rtrim((string) config('app.frontend_url'), '/')."/subscription-invoices/{$this->invoice->id}",
        ]);
    }
}
