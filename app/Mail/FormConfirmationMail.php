<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a site visitor their form submission arrived, with a copy of what they sent.
 */
class FormConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{label: string, value: string}>  $values
     */
    public function __construct(
        public string $siteName,
        public string $text,
        public array $values,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Ваша заявка получена — {$this->siteName}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.form-confirmation');
    }
}
