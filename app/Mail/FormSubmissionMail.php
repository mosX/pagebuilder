<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies a company about a new submission of a form on its site.
 */
class FormSubmissionMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{label: string, value: string}>  $values
     */
    public function __construct(
        public string $siteName,
        public string $collectionLabel,
        public array $values,
        public ?string $adminUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Новая заявка: {$this->collectionLabel} — {$this->siteName}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.form-submitted');
    }
}
