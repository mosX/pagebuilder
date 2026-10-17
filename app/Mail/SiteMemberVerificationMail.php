<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Asks a site visitor to confirm the e-mail of their new account.
 */
class SiteMemberVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $siteName,
        public string $memberName,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Подтвердите e-mail — {$this->siteName}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.site-member-verification');
    }
}
