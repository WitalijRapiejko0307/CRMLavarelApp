# Вариации товара (webhook, SalesRender, склад)

**Дата:** 19.11.2026
**Статус:** реализовано; миграции не применялись, автотесты (`tests/Feature/ProductVariationTest.php`) не запускались — в окружении нет `pdo_sqlite`
**Контекст:** колл-центр Sales Render просит поддержку товаров с вариацией (цвет, размер). Вариацию клиент вписывает в скрипт сайта, она уходит в SR вместе с товаром, а при синхронизации возвращается обратно.

## Цель

Хранить вариацию позиции заявки, передавать её из webhook в SR, получать из SR при синхронизации и вести остатки и продажи по вариациям. Существующая логика для товаров без вариаций не меняется.

## Текущее состояние

- Заявка хранит товары параллельными JSON-массивами `goods` / `quantities` / `prices` ([`Order`](../../app/Models/Order.php)); товар ищется по имени (`Product.name`), `sr_item_id` берётся у найденного товара.
- [`SalesRenderService::sendOrder`](../../app/Services/SalesRenderService.php) шлёт жёсткое `variation: 1`; `fetchOrder` не запрашивает `sku.variation`.
- [`SyncSalesRenderJob::extractCart`](../../app/Jobs/SyncSalesRenderJob.php) берёт имя, количество и цену; вариация теряется.
- Остаток и продажи на уровне товара: `Product.stock`, `sold_count`, `sold_amount`; списания в `order_stock_movements` по `product_id` ([`OrderStockAndIncomeService`](../../app/Services/OrderStockAndIncomeService.php)).
- SR всегда создаёт у товара минимум одну вариацию: для товара без вариаций это `number: 1`, `property: "Основная"`.

## Решения

1. Выбор вариации в уже созданной заявке в SR не меняем.
2. Сайт передаёт в webhook необязательный `variation`: номер вариации в колл-центре (целое ≥ 1). Клиент сам вписывает его в скрипт.
3. Название и нумерацию вариаций клиент заполняет сам (в карточке товара), согласуя с колл-центром. Привязка по `number` в рамках товара, как `sr_item_id` у товара. Если из SR приходит неизвестный номер `N ≠ 1`, вариация создаётся автоматически (название из `property`).
4. **Товар без вариации**: номер `1`, которого нет в справочнике товара. Запись не создаётся, в заявке `null`.
5. **`variation` в SR отправляется только для товаров с вариациями.** Для остальных мутация идёт без изменений (`variation: 1`). Если SR отклонил `addOrder` с `variation ≠ 1`, отправка повторяется один раз с `variation: 1`.
6. **Склад, вариант Б**: остаток и продажи ведутся по вариациям; `Product.stock` = сумма остатков вариаций.

## Правила вариаций

1. Входящий номер `N` для товара P:
   - у P есть запись с `number = N`: это вариация;
   - записи нет и `N = 1`: «без вариации» (`null`);
   - записи нет и `N ≠ 1`: создать вариацию `N`.
2. Товар становится **вариативным**, когда у него появляется первая запись (вручную или автоматически). В одной транзакции с `lockForUpdate` на товаре: создаётся вариация `1`, ей передаются текущие `stock`, `sold_count`, `sold_amount` товара; затем создаётся новая. `Product.stock` и `sold_*` становятся суммой по вариациям.
3. У вариативного товара `null` в заявке и `variation_id = null` в движении означают вариацию `1`.
4. `name` вариации заполняется из `property` SR, только если пусто. Заполненное не перетирается.

## Изменения

### БД
- `product_variations`: `id`, `tenant_id`, `product_id` (cascade), `number` (unsigned int), `name` (nullable), `stock` (unsigned int, 0), `sold_count` (unsigned int, 0), `sold_amount` (decimal 12,2, 0), timestamps; `unique(product_id, number)`. Модель `ProductVariation` с `TenantScope`.
- `orders.variations`: nullable JSON, параллельный `goods`. Элемент `{"number": 6, "name": "черный"}` или `null` (снимок на момент заявки).
- `order_stock_movements.variation_id`: nullable, FK на `product_variations`, nullOnDelete.

### Сервис `ProductVariationService`
Единая точка правил: `resolve(Product, ?int $number, ?string $property): ?ProductVariation`, `makeVariational(Product)`, `recalcProductTotals(Product)`. Webhook, синхронизация, склад и ручные формы ходят только через него.

### Webhook ([`WebhookController::lead`](../../app/Http/Controllers/WebhookController.php))
- Правило `'variation' => ['nullable', 'integer', 'min:1']`, docblock.
- Если `variation` передан и товар вариативный: `resolve`; в заказ `'variations' => [{number, name}]`. Иначе `variations = null`; для невариативного товара поле игнорируется.
- В `sendOrder` номер передаётся только для вариативного товара (см. решение 5).

### SalesRender
- `sendOrder(Order, ?int $srItemId, ?int $variation = null)`: `variation: <N>` только если задан; при ошибке `addOrder` с `N ≠ 1` повтор с `1` (в лог номер и ошибка SR).
- `fetchOrder`: добавить `variation { number property }` в `sku`.
- `extractCart` возвращает `variations`; `isCartChanged` их сравнивает; `goods`/`quantities`/`prices`/`variations` пишутся одним `update` в `applyNonFinalUpdates` и `applyConfirmedData`. Каждая позиция проходит через `resolve`.

### Склад и продажи (`OrderStockAndIncomeService`)
- Ключ чистого списания `product_id:variation_id`; `recordMovement` пишет `variation_id`.
- `applyDeduction`: вариация определяется через `resolve` (для вариативного товара `null` = вариация 1; неизвестный номер = вариация 1 с записью в лог); списание с вариации, затем `recalcProductTotals`. Невариативный товар: поведение прежнее.
- `applyRestore`: возврат в ту же вариацию из движений; движения с `variation_id = null` у уже вариативного товара идут в вариацию 1.
- `orderFullyDeducted`, `adjustProductSales` работают по тому же ключу.
- Транзакция и `lockForUpdate` на вариации.

### Интерфейс
- **Создание товара с вариациями** (`Products/Index.vue`, форма создания; `ProductController::store`): в форме есть блок «Вариации» (необязательный). Каждая строка: номер в колл-центре, название, начальный остаток. Правила:
  - если вариации заданы, поле `stock` товара не вводится, а равно сумме остатков вариаций; `sold_*` стартуют с 0;
  - обязательна вариация с номером `1` (иначе у `null` в заявках нет вариации, куда его относить); номера уникальны, целые ≥ 1; название обязательно;
  - товар и все вариации создаются в одной транзакции; без блока «Вариации» товар создаётся как сейчас (`stock` обязателен);
  - валидация: `variations` — `nullable|array`, `variations.*.number` — `required|integer|min:1|distinct`, `variations.*.name` — `required|string|max:255`, `variations.*.stock` — `required|integer|min:0`.
- `Products/Index.vue`, `ProductController` (редактирование): у вариативного товара список вариаций (номер, название, остаток, продано), добавление новых вариаций, редактирование названия, остаток правится по вариации; остаток товара показывается суммой и не редактируется. Удаление вариации с остатком или движениями запрещено.
- Заказы: хелпер `Order::goodLabel(int $i)` = «Название (вариация)»; использовать в `Orders/Show.vue`, `Orders/Index.vue` (`formatGoods`), чек-листе упаковки, листе курьера, `Courier/Index.vue`, `Europochta/Create.vue`.
- Ручные формы `Orders/Create.vue` и `Orders/Show.vue`: для вариативного товара рядом с выбором товара показывать выбор вариации (см. «Конфликты», п. 1).
- Образец кода в Настройки → Webhook (`webhookExample`, [`Settings/Index.vue`](../../resources/js/Pages/Settings/Index.vue)) и `README.md`:

  ```php
  "    'variation' => $variation ?? null, // необязательно: номер вариации товара в колл-центре (целое число ≥ 1). Не передавайте, если у товара нет вариаций",
  ```

## Конфликты со старой логикой и их разрешение

Проверено по всему коду (`goods`, `stock`, `sold_*`, `OrderStockMovement`).

1. **Ручное создание и правка заявок** (`Orders/Create.vue`, `Orders/Show.vue`, `OrderController::store/update`). Это основной путь для магазинов со своим колл-центром (`storeHasInternalCallCenter`); товар выбирается из каталога по имени, вариации нет. Без выбора вариации любой такой заказ вариативного товара списывался бы с вариации 1. Решение: добавить `variations` в валидацию `OrderController` (правила `goods`/`quantities`/`prices`), в `Order::$fillable`/`$casts` и в `Order::CALL_CENTER_EDITABLE_FIELDS` (иначе оператор колл-центра не сможет сохранить вариацию), плюс выбор вариации в формах.
2. **Параллельные массивы**: `Show.vue` добавляет/удаляет строки (`form.goods.push/splice`) и upsell/cross-позиции (`addOfferLine`, `currentGoodsState`) без `variations`. Все эти места должны добавлять и удалять элементы `variations` синхронно. Если длина `variations` не равна длине `goods`, вариации считаются отсутствующими.
3. **CSV-импорт** (`CsvOrderReader`, `CsvOrderLineParser`, `OrderController` ~строки 184 и 233): вариаций в файле нет, `variations = null`. Для вариативного товара это вариация 1; это ограничение нужно показать в подсказке импорта.
4. **Синхронизация из SR** целиком заменяет `goods`/`prices`; вариации пишутся тем же `update`, иначе корзина разъедется. Порядок позиций в SR может отличаться.
5. **Остаток товара** читают `ProductController` (`store` требует `stock`, `update` принимает `stock_delta`), `OrderController` (списки товаров с `stock`), `Products/Index.vue`. У вариативного товара `stock` управляется только суммой: `stock_delta` для него отклонять (422) с понятным сообщением; чтение `stock` везде работает без изменений (колонка хранит сумму).
6. **Клампинг `max(0, …)`** при списании маскирует расхождения; сумма пересчитывается после каждого движения, а не вычитается.
7. **Старые движения** (`variation_id = null`) у товара, ставшего вариативным, трактуются как вариация 1 при возврате и в `orderFullyDeducted`.
8. **Статистика продаж** (`adjustProductSales`) и дайджест (`DailyDigestService`): дайджест группирует по имени товара и вариации не учитывает; в первой версии это допустимо.
9. **Belpost, Evropost, `BelpostLabelService`, `ProductLinkResolver`, `SmsService`**: используют только названия товаров и вес товара. Вариация имя не меняет, вес общий у вариаций. Изменений не требуют.
10. **`CustomerService` / `CustomerController`**: «последний заказ» показывает `goods`; вариации не выводятся, на логику не влияет.
11. **Поиск заказов** по `goods` (`OrderController` ~строка 327) вариации не ищет; допустимо.
12. **Отправка в SR с неверным номером**: `addOrder` отклоняется, повтор с `variation: 1`. Если отклонён и он, поведение прежнее: `external_id` не записывается.
13. **Название товара из SR** (`sku.item.name`) может отличаться от `Product.name`; это существующее ограничение, вариации его не исправляют.
14. **Параллельные заявки**: два webhook с новым номером конфликтуют на `unique(product_id, number)`; использовать `firstOrCreate` с обработкой `UniqueConstraintViolationException` и `lockForUpdate` при переходе в вариативный.
15. **Старые заявки**: `variations = null`; при первой синхронизации незавершённых заявок `isCartChanged` один раз сработает и заполнит вариации.
16. **GAS-реализация** (`GS/`) в объём не входит.

## Порядок реализации

1. Миграции, модель `ProductVariation`, `ProductVariationService`.
2. Webhook и `sendOrder` (включая повтор с `variation: 1`).
3. `fetchOrder` / `extractCart` / `isCartChanged` в синхронизации.
4. Склад: ключ `product_id:variation_id`, пересчёт сумм.
5. `OrderController`, `Order` (fillable, casts, `CALL_CENTER_EDITABLE_FIELDS`), `goodLabel`.
6. Интерфейс: `Products/Index.vue`, формы заказов, отображение вариации.
7. Образец кода в настройках и `README.md`.
8. Тесты:
   - webhook: сохранение `variation`; без него `null`; невариативный товар игнорирует поле; нечисловое значение даёт 422;
   - правила: `1` без записи даёт `null`; `2` создаёт вариации 1 и 2 с переносом остатка и продаж;
   - SR: `variation` в мутации только для вариативного товара; повтор с `1` при ошибке;
   - синхронизация: `name` заполняется и не перетирается; «Основная» при `1` без записи не создаёт вариацию;
   - создание товара: с вариациями (без `1` даёт 422; `stock` товара равен сумме; без вариаций работает как прежде);
   - склад: списание и возврат по вариации; `null` у вариативного товара идёт в вариацию 1; сумма остатков равна `Product.stock`;
   - ручные формы: сохранение `variations` оператором колл-центра; рассинхрон длин игнорируется.

   Автотесты не запускаются локально: в окружении нет `pdo_sqlite`.
