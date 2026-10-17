<!DOCTYPE html>
<html lang="ru">
    <body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5;">
        <h2 style="margin: 0 0 16px;">{{ $siteName }}</h2>
        <p style="margin: 0 0 20px; white-space: pre-line;">{{ $text }}</p>

        <p style="margin: 0 0 8px; color: #6b7280;">Вы отправили:</p>
        <table cellpadding="8" style="border-collapse: collapse; width: 100%; max-width: 600px;">
            @foreach ($values as $item)
                <tr style="border-bottom: 1px solid #e5e7eb;">
                    <td style="width: 35%; color: #6b7280; vertical-align: top;">{{ $item['label'] }}</td>
                    <td style="white-space: pre-line;">{{ $item['value'] }}</td>
                </tr>
            @endforeach
        </table>

        <p style="margin-top: 24px; color: #6b7280; font-size: 13px;">
            Это автоматическое письмо. Если вы не отправляли заявку, просто проигнорируйте его.
        </p>
    </body>
</html>
