@php
    $stageLabel = $stage === 'advanced' ? 'Второй опрос' : 'Первый опрос';
    $missingLabels = [
        'speed' => 'Скорость',
        'blanks' => 'Бланки и трекинг',
        'stock' => 'Склад',
        'reports' => 'Отчёты',
        'other' => 'Другое',
    ];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Отзыв CRM</title>
</head>
<body style="font-family: sans-serif; line-height: 1.5; white-space: pre-wrap;">Этап: {{ $stageLabel }}
Имя: {{ $user->name }}
Email: {{ $user->email }}
Компания: {{ $tenantName }}

@if ($stage === 'early')
Удобство ведения заказов (1–5): {{ $answers['orders_score'] ?? '—' }}
Понятность Белпочты и Европочты (1–5): {{ $answers['postal_score'] ?? '—' }}
Чего не хватает в первую очередь: {{ $missingLabels[$answers['missing'] ?? ''] ?? ($answers['missing'] ?? '—') }}
@if (($answers['missing'] ?? '') === 'other')
Уточнение (другое): {{ $answers['missing_other'] ?? '' }}
@endif
@if (!empty($answers['improve']))
Что улучшить: {{ $answers['improve'] }}
@endif
@else
Общая оценка (1–5): {{ $answers['score'] ?? '—' }}
Комментарий:
{{ $answers['comment'] ?? '' }}
@if (!empty($answers['telegram']))
Telegram: {{ $answers['telegram'] }}
@endif
@endif
</body>
</html>
