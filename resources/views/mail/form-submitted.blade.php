<!DOCTYPE html>
<html lang="ru">
    <body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.5;">
        <h2 style="margin: 0 0 16px;">Новая заявка с сайта «{{ $siteName }}»</h2>
        <p style="margin: 0 0 16px; color: #6b7280;">Коллекция: {{ $collectionLabel }}</p>

        <table cellpadding="8" style="border-collapse: collapse; width: 100%; max-width: 600px;">
            @foreach ($values as $item)
                <tr style="border-bottom: 1px solid #e5e7eb;">
                    <td style="width: 35%; color: #6b7280; vertical-align: top;">{{ $item['label'] }}</td>
                    <td style="white-space: pre-line;">{{ $item['value'] }}</td>
                </tr>
            @endforeach
        </table>

        @if ($adminUrl)
            <p style="margin-top: 24px;">
                <a href="{{ $adminUrl }}" style="color: #2563eb;">Открыть заявки в админке</a>
            </p>
        @endif
    </body>
</html>
