<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invites a person to join a company in the admin.
 */
class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $companyName,
        public string $inviterName,
        public string $roleLabel,
        public string $url,
        public int $validDays,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Приглашение в компанию «{$this->companyName}»");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.invitation');
    }
}
