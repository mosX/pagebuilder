<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A short e-mail with one action link: password reset or e-mail confirmation,
 * for admin users and site visitors alike.
 */
class AccountLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $title,
        public string $greeting,
        public string $text,
        public string $actionLabel,
        public string $url,
        public string $note,
    ) {}

    public static function passwordReset(string $siteName, string $name, string $url, int $minutes): self
    {
        return new self(
            "Сброс пароля — {$siteName}",
            "Здравствуйте, {$name}!",
            "Кто-то (надеемся, вы) запросил сброс пароля для аккаунта на «{$siteName}».",
            'Задать новый пароль',
            $url,
            "Ссылка действует {$minutes} минут. Если вы не запрашивали сброс, просто проигнорируйте письмо — пароль не изменится.",
        );
    }

    public static function emailVerification(string $siteName, string $name, string $url): self
    {
        return new self(
            "Подтвердите e-mail — {$siteName}",
            "Здравствуйте, {$name}!",
            'Подтвердите, что этот адрес ваш: на него будут приходить уведомления и ссылки для восстановления доступа.',
            'Подтвердить e-mail',
            $url,
            'Если вы не регистрировались, просто проигнорируйте письмо.',
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.account-link');
    }
}
