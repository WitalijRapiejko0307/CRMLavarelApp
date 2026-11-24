# BaseCRM — Фаза 1

Laravel 8 + Inertia.js + Vue 3 + MySQL 5.7

## Быстрый старт (локальная разработка)

```bash
# 1. Установить зависимости PHP
composer install

# 2. Скопировать .env и сгенерировать ключ
cp .env.example .env
php artisan key:generate

# 3. Настроить БД в .env (DB_DATABASE, DB_USERNAME, DB_PASSWORD)

# 4. Запустить миграции и сидер
php artisan migrate:fresh --seed

# 5. Установить Node зависимости и собрать ассеты
npm install
npm run dev        # или npm run watch

# 6. Запустить dev-сервер
php artisan serve
```

Откройте http://localhost:8000

**Учётные данные:**
- admin@crm.by / password
- manager@crm.by / password

---

## Деплой на hoster.by

```bash
# Локально: сборка ассетов для production
npm run build

# Загрузить на сервер:
# - /home/user/crm/         (всё кроме /public)
# - /home/user/public_html/ (содержимое /public)

# Настроить .env на сервере
# Запустить по SSH:
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Cron и очередь

Бланки Белпочты собираются в CRM при скачивании и не требуют очереди. Cron нужен для фоновых задач (трекинг, письма, очистка логов):

```
* * * * * cd /home/user/crm && php artisan schedule:run >> /dev/null 2>&1
```

В `.env`: `QUEUE_CONNECTION=database`, таблица `jobs` должна существовать.

**Диагностика ошибок бланков:** таблица `belpost_label_logs` (результат, код, длительность) и `storage/logs/belpost-labels-*.log`.

```sql
SELECT created_at, mail_batch_id, kind, result, error_code, error_message, duration_ms
FROM belpost_label_logs WHERE tenant_id = ? ORDER BY id DESC LIMIT 50;
```

### Ярлыки Европочты

Работают только при API v1.8.2 (`ep_api_version = new`). На странице `/europochta` секция «Оформлены» показывает заявки в статусе «Оформлен» (30 дней, до 200). Оператор отмечает заявки и скачивает ярлыки (`POST /europochta/labels`): сервер скачивает PDF по каждому треку (`GET /api/external/label/address|thermal/{трек}`) и склеивает их в один файл (1 ярлык = 1 страница). Если склейка не удалась, выдаётся ZIP из отдельных PDF; если часть ярлыков не скачалась, выдаётся всё скачанное, а ошибки по трекам приходят в заголовке `X-Label-Errors`. Формат ярлыка (обычный A5 или термо 10×10) запоминается для магазина (`ep_label_format`, по умолчанию A5).

Для разбора проблем: лог `storage/logs/europost-labels-*.log` (статус, `Content-Type`, размер, версия PDF по каждому треку, ошибки склейки, метаданные пробы пакетного метода). При сбое склейки исходные PDF сохраняются в `storage/app/europost-label-debug/` и удаляются через 14 дней (они содержат данные получателей). Отключение: `EVROPOST_LABEL_DEBUG_SAVE=false`, `EVROPOST_LABEL_PROBE=false`. Нужны расширение PHP `zip` и пакеты `setasign/fpdi`, `setasign/fpdf`.

### Приоритетная служба и ОПС отправки

В «Настройках» → «Магазин» выбирается служба доставки по умолчанию (`default_courier`); если выбрана Европочта, но она не подключена, подставляется Белпочта. ОПС отправки Европочты выбирается в настройках через поиск; в заявке его можно заменить только для этой заявки (`orders.europochta_store_start_id`).

### Webhook

```
POST https://crm.your-domain.by/api/webhook/lead

Headers:
  Content-Type: application/json
  X-Webhook-Token: <webhook_secret из настроек тенанта>

Body:
  {
    "name": "Иван Иванов",
    "phone": "291234567",
    "offer": "Товар А",
    "options": "49.90",
    "comment": "Позвонить после 18:00",
    "variation": "Черный",
    "source": "site"
  }
```

- `phone` — 9 цифр (без +375) или 12 цифр с кодом `375`; сохраняется как `375XXXXXXXXX`
- `options` — цена товара в руб. (число), попадает в `prices[0]`
- `comment` — необязательный текст (до 2000 символов), попадает в комментарий заказа и уходит в SalesRender
- `variation` — необязательное название вариации товара (как `offer`, регистр не важен), должно совпадать с названием в «Товары → Вариации». Нераспознанное значение игнорируется, заявка не отклоняется. В колл-центр уходит номер найденной вариации; если колл-центр его не принял, заявка отправляется с вариацией 1

Получить `webhook_secret` после сидера:
```sql
SELECT `key`, CONVERT(AES_DECRYPT(FROM_BASE64(`value`), ?) USING utf8mb4) 
FROM tenant_settings WHERE `key` = 'webhook_secret';
```

Или через Tinker:
```bash
php artisan tinker
App\Models\TenantSetting::withoutGlobalScopes()->where('key','webhook_secret')->first()->value
```

---

## Структура проекта

```
hosting/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/LoginController.php     — вход/выход
│   │   │   ├── OrderController.php          — CRUD заказов
│   │   │   └── WebhookController.php        — POST /api/webhook/lead
│   │   └── Middleware/
│   │       ├── HandleInertiaRequests.php    — share auth/flash
│   │       └── SetTenant.php                — current_tenant_id в DI
│   ├── Models/
│   │   ├── Order.php          — заказы + STATUSES + адрес
│   │   ├── Product.php        — товары + склад
│   │   ├── Tenant.php
│   │   ├── TenantSetting.php  — encrypted значения
│   │   ├── OrderStatusHistory.php
│   │   └── MailBatch.php
│   ├── Observers/
│   │   └── OrderObserver.php  — stock при Отправлено/Возврат
│   └── Scopes/
│       └── TenantScope.php    — автофильтр по tenant_id
├── database/migrations/       — 8 миграций
├── database/seeders/          — тестовый тенант + пользователи
├── resources/
│   ├── js/
│   │   ├── Pages/
│   │   │   ├── Auth/Login.vue      — форма входа
│   │   │   └── Orders/
│   │   │       ├── Index.vue       — TanStack Table + фильтры
│   │   │       └── Show.vue        — карточка + история статусов
│   │   ├── Layouts/AppLayout.vue   — шапка + flash
│   │   └── Components/
│   │       └── OrderStatusBadge.vue
│   └── views/app.blade.php    — Inertia root
└── routes/
    ├── web.php    — auth + orders
    └── api.php    — webhook
```

---

## Фазы реализации

| Фаза | Что | Статус |
|------|-----|--------|
| 1 | Фундамент: Auth, Orders CRUD, Webhook, Observer | ✅ Done |
| 2 | Белпочта: BelpostService, createList/createItem, PDF | Planned |
| 3 | Фоновые: tracking, SalesRender, SMS, cron | Planned |
| 4 | Европочта, склад | Planned |
| 5 | Финансы, CSV, multi-tenant UI | Planned |

---

## Документация

Планы миграции, описания фич и fix-notes: [docs/README.md](./docs/README.md)
