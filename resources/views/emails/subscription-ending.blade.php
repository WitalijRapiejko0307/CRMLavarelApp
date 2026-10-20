<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Подписка CRM заканчивается</title>
</head>
<body style="font-family: sans-serif; line-height: 1.5;">
    <p>Здравствуйте!</p>
    <p>
        Подписка компании «{{ $tenant->name }}» в CRM закончится {{ $endsAtLabel }}.
        После этой даты доступ к CRM станет только для просмотра (режим read-only).
    </p>
    <p>С уважением,<br>команда CRM</p>
</body>
</html>
