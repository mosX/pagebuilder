<!DOCTYPE html>
<html lang="ru">
    <body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5;">
        <h2 style="margin: 0 0 16px;">Здравствуйте, {{ $memberName }}!</h2>
        <p style="margin: 0 0 16px;">
            Вы зарегистрировались на сайте «{{ $siteName }}». Подтвердите e-mail, чтобы видеть свои заявки в личном кабинете.
        </p>
        <p style="margin: 0 0 24px;">
            <a href="{{ $url }}" style="display: inline-block; padding: 10px 20px; background: #2563eb; color: #ffffff; text-decoration: none; border-radius: 6px;">Подтвердить e-mail</a>
        </p>
        <p style="margin: 0; color: #6b7280; font-size: 13px;">
            Ссылка действует 3 дня. Если вы не регистрировались, просто проигнорируйте это письмо.
        </p>
    </body>
</html>
