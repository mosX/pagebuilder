<!DOCTYPE html>
<html lang="ru">
    <body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5;">
        <h2 style="margin: 0 0 16px;">Приглашение в компанию «{{ $companyName }}»</h2>
        <p style="margin: 0 0 16px;">
            {{ $inviterName }} приглашает вас присоединиться к компании «{{ $companyName }}» с ролью «{{ $roleLabel }}».
        </p>
        <p style="margin: 0 0 24px;">
            <a href="{{ $url }}" style="display: inline-block; padding: 10px 20px; background: #2563eb; color: #ffffff; text-decoration: none; border-radius: 6px;">Принять приглашение</a>
        </p>
        <p style="margin: 0; color: #6b7280; font-size: 13px;">
            Ссылка действует {{ $validDays }} дней. Если вы не ждали этого письма, просто проигнорируйте его.
        </p>
    </body>
</html>
