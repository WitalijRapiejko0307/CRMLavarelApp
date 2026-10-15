<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Напоминание об удалении аккаунта</title>
</head>
<body style="font-family: sans-serif; line-height: 1.5;">
    <p>Здравствуйте!</p>
    <p>
        Напоминаем, что аккаунт «{{ $tenant->name }}» в CRM запланирован к удалению
        {{ $deletionDateLabel }}.
    </p>
    <p>
        Если вы передумали, отмените удаление в настройках аккаунта до указанной даты.
    </p>
    <p>С уважением,<br>команда CRM</p>
</body>
</html>
