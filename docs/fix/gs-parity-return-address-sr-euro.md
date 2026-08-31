# GS parity: возврат Белпочты, адрес, SalesRender, Европочта

**Дата:** 31.08.2026  
**Статус:** implemented  
**План:** [`docs/feature/laravel-gs-parity.md`](../../../docs/feature/laravel-gs-parity.md)

## Что сделано

Laravel hosting выровнен с актуальной логикой GS CRM по шести блокам.

1. **Токен белпочты** — в Настройках под полем `auth_token_bp` раскрывается инструкция (только при `canEditSettings`).
2. **Возвратный трекинг** — статус «Возврат в пути», разбор `steps[]`, без ложного «В отделении» и SMS; склад возвращается из «Отправлено» / «В отделении» / «Возврат в пути».
3. **Адрес** — поиск по 6-значному индексу, fallback `city + street`; в createItem сравнение через `addressPartsMatch`.
4. **HTTP 401 белпочты** — подсказка проверить токен в Настройках; на партии `error_message` для `api_error` показывается целиком.
5. **SalesRender** — корзина и комментарий до финального статуса; `Отмена` / `Дубли` / `Спам` → `Отказ(Ошибка)`; баннер failures как у утреннего трекинга.
6. **Европочта new API** — из payload убран `declared_amount`, `payment_amount` оставлен.

## Проверка

- Unit: парсер возврата Белпочты, `extractPostcode` / `autoResolve`, `Order::STATUSES`.
- Feature: склад `Возврат в пути` → «Возврат»; payload Европочты; active tracking query; SyncSalesRender корзина и Спам/Дубли.
