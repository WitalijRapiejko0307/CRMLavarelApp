<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Курьер · заказ №{{ $order_id }}</title>
    <style>
        @page { size: A4; margin: 16mm; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #111;
        }
        h1 {
            font-size: 16px;
            font-weight: bold;
            margin: 0 0 14px 0;
        }
        table.meta {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        table.meta td {
            vertical-align: top;
            padding: 4px 0;
        }
        table.meta .label {
            width: 140px;
            color: #444;
            padding-right: 10px;
        }
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0 14px 0;
        }
        table.items th, table.items td {
            text-align: left;
            vertical-align: top;
            padding: 6px 8px;
            border-bottom: 1px solid #ddd;
        }
        table.items th {
            font-weight: bold;
            border-bottom: 1px solid #999;
        }
        .num, .sum {
            white-space: nowrap;
        }
        .sum {
            text-align: right;
        }
        .total {
            text-align: right;
            font-weight: bold;
            font-size: 13px;
            margin: 4px 0 16px 0;
        }
        .checks {
            margin-top: 18px;
        }
        .check {
            margin: 8px 0;
        }
        .box {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 1px solid #111;
            margin-right: 8px;
            vertical-align: middle;
        }
        .muted {
            color: #555;
        }
    </style>
</head>
<body>
    <h1>Курьер · заказ №{{ $order_id }}</h1>

    <table class="meta">
        <tr>
            <td class="label">Дата</td>
            <td>{{ $date }}</td>
        </tr>
        <tr>
            <td class="label">ФИО</td>
            <td>{{ $full_name }}</td>
        </tr>
        <tr>
            <td class="label">Телефон</td>
            <td>{{ $phone }}</td>
        </tr>
        <tr>
            <td class="label">Адрес</td>
            <td>{{ $address }}</td>
        </tr>
        <tr>
            <td class="label">Комментарий</td>
            <td>{{ $comment !== '' ? $comment : '—' }}</td>
        </tr>
        <tr>
            <td class="label">Апсейл</td>
            <td>{{ $upsell !== '' ? $upsell : '—' }}</td>
        </tr>
        <tr>
            <td class="label">Кросс-селл</td>
            <td>{{ $cross_sell !== '' ? $cross_sell : '—' }}</td>
        </tr>
    </table>

    <div>Состав</div>
    <table class="items">
        <thead>
            <tr>
                <th>Товар</th>
                <th class="num">Кол-во</th>
                <th class="sum">Цена</th>
                <th class="sum">Сумма</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $item)
                <tr>
                    <td>{{ $item['name'] }}</td>
                    <td class="num">{{ $item['qty'] }}</td>
                    <td class="sum">{{ number_format($item['price'], 2, '.', ' ') }}</td>
                    <td class="sum">{{ number_format($item['sum'], 2, '.', ' ') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="muted">Нет товаров</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="total">Итог: {{ number_format($total, 2, '.', ' ') }} BYN</div>

    <div class="checks">
        <div class="check"><span class="box"></span> собрано</div>
        <div class="check"><span class="box"></span> передано</div>
        <div class="check"><span class="box"></span> оплата получена</div>
    </div>
</body>
</html>
