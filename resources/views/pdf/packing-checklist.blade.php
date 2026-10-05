<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { size: A4; margin: 16mm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #111;
        }
        h1 {
            font-size: 16px;
            font-weight: bold;
            margin: 0 0 12px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            text-align: left;
            vertical-align: top;
            padding: 6px 8px;
            border-bottom: 1px solid #ddd;
        }
        th {
            font-weight: bold;
            border-bottom: 1px solid #999;
        }
        .num, .sum {
            white-space: nowrap;
        }
        .sum, .total {
            text-align: right;
        }
        .empty {
            color: #555;
            margin: 24px 0;
        }
        .footer {
            margin-top: 14px;
            font-size: 12px;
            font-weight: bold;
            text-align: right;
        }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>

    @if (count($rows) === 0)
        <p class="empty">Нет посылок для сборки</p>
    @else
        <table>
            <thead>
                <tr>
                    <th class="num">№</th>
                    <th>ФИО</th>
                    <th>Товары</th>
                    <th class="sum">Сумма</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td class="num">{{ $row['id'] }}</td>
                        <td>{{ $row['full_name'] }}</td>
                        <td>{{ $row['items_label'] }}</td>
                        <td class="sum">{{ number_format($row['sum'], 2, '.', ' ') }} BYN</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        Итого: {{ number_format($total, 2, '.', ' ') }} BYN
    </div>
</body>
</html>
