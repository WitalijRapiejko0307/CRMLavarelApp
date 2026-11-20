# Fix: заявка с сайта без `source` получала источник «site» вместо ссылки со склада

**Файл:** [`app/Http/Controllers/WebhookController.php`](../../app/Http/Controllers/WebhookController.php)  
**Статус:** реализовано  
**Scope:** webhook `POST /api/webhook/lead`, колонка «Источник» заказа (`orders.source`)

## Симптом

Сайт присылает заявку без поля `source`. В CRM у заказа в «Источнике» стоит `site`, хотя у товара на складе указана ссылка на страницу (`page_url`). Ссылка в «Источник» не попадает, а метка `site` ничего не говорит о заявке.

## Причина

В `WebhookController` значение по умолчанию было задано жёстко:

```php
'source' => $data['source'] ?? 'site',
```

`Product.page_url` при создании заказа не читался: он нужен только `ProductLinkResolver` для ссылки на товар в карточке и в списке заказов.

## Решение

Источник определяется по порядку, берётся первое непустое значение:

1. `source`, присланный сайтом;
2. `page_url` товара со склада (товар ищется по `offer`, поиск уже выполняется выше создания заказа);
3. иначе пустое значение (`NULL`), метка `site` больше не подставляется.

```php
$source = trim((string) ($data['source'] ?? ''));
if ($source === '') {
    $pageUrl = trim((string) ($product?->page_url ?? ''));
    $source  = $pageUrl !== '' ? $pageUrl : null;
}
```

Колонка `orders.source` допускает `NULL`, миграция не нужна. Ссылку в `page_url` склад нормализует сам (`UrlNormalizer`, добавляет `https://`).

## Проверка, что существующая логика не ломается

| Место | Как ведёт себя |
|---|---|
| Список заказов (`Orders/Index.vue`, «Источник») | `row[field] \|\| '—'`, пустое значение показывается прочерком |
| Карточка заказа (`Orders/Show.vue`) | `order.source ?? '—'` |
| Отправка в SalesRender (`SalesRenderService::sendOrder`) | теперь ссылка со склада уходит в `source.uri`, если сайт не прислал свою; пустое значение не передаётся |
| Ручное создание заказа (`OrderController::store`) | не затронуто, по-прежнему `manual` |
| Импорт CSV | не затронут, колонка `source` берётся из файла |
| Аналитика, отчёты, сегменты | `orders.source` в них не используется |
| Существующие тесты webhook | передают `source` явно, результат не меняется |

Старые заказы со значением `site` не изменяются.

## Тесты

В [`tests/Feature/WebhookUtmTest.php`](../../tests/Feature/WebhookUtmTest.php) добавлены:
- `test_missing_source_and_no_page_url_stays_empty`: нет `source` и нет `page_url`, значит `NULL`;
- `test_missing_source_falls_back_to_product_page_url`: `source` берётся из `page_url` товара;
- `test_passed_source_wins_over_product_page_url`: присланный `source` приоритетнее ссылки со склада;
- `test_full_url_source_is_stored`: полная ссылка длиннее 50 символов сохраняется без изменений.

На машине разработки тесты пропускаются (нет `pdo_sqlite`), поэтому их нужно запустить там, где расширение есть: `vendor/bin/phpunit tests/Feature/WebhookUtmTest.php`.

## Не входит в фикс

В `Settings/Index.vue` (~стр. 1045) пример кода для лендинга содержит `'source' => 'site'`. Сайты, скопировавшие этот пример, по-прежнему будут присылать `site` явно, и ссылка со склада для них не подставится. Если нужен пустой источник и для них, из примера стоит убрать эту строку.
