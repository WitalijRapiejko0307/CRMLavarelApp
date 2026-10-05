<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Обращение в поддержку CRM</title>
</head>
<body style="font-family: sans-serif; line-height: 1.5; white-space: pre-wrap;">Имя: {{ $user->name }}
Email для ответа: {{ $replyEmail }}
Email аккаунта: {{ $user->email }}
Компания: {{ $tenantName }}

Сообщение:
{{ $messageText }}</body>
</html>
