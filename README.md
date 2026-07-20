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

### Cron на hoster.by

Добавить в crontab:
```
* * * * * cd /home/user/crm && php artisan schedule:run >> /dev/null 2>&1
```

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
    "source": "site"
  }
```

- `phone` — 9 цифр (без +375) или 12 цифр с кодом `375`; сохраняется как `375XXXXXXXXX`
- `options` — цена товара в руб. (число), попадает в `prices[0]`

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
