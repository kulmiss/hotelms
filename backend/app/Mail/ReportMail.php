<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A scheduled report or a smart alert, with the report as an Excel file when there is one. */
class ReportMail extends Mailable
{
    use Branded, Queueable, SerializesModels;

    /** @param  list<string>  $lines */
    public function __construct(
        public string $hotelName,
        public string $title,
        public array $lines,
        public ?string $fileName = null,
        public ?string $fileContent = null,
        public ?string $link = null,
    ) {}

    public function envelope(): Envelope
    {
        return $this->brandedEnvelope("{$this->title} · {$this->hotelName}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.report', with: $this->brandData());
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return $this->fileName && $this->fileContent !== null
            ? [Attachment::fromData(fn () => $this->fileContent, $this->fileName)->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')]
            : [];
    }
}
