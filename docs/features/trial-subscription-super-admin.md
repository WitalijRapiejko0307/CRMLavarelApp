# Пробный период 14 дней, self-registration, super-admin

**Дата:** 22.07.2026  
**Статус:** implemented  
**Контекст:** SaaS-модель доступа для multi-tenant CRM (Laravel + Inertia + Vue 3)

## Цель

Добавить 14-дневный пробный период для новых тенантов с self-registration, read-only режимом после истечения trial, сохранением приёма заказов через webhook, ролью super-admin для управления доступами и активацией существующего тестового тенанта.

## Зафиксированные требования

| Требование | Решение |
|------------|---------|
| Онбординг | Self-registration (`/register`) |
| После trial | Read-only: GET/просмотр — да, мутации — нет |
| Webhook | Всегда создаёт заказ; SalesRender — только для `trial`/`active` |
| Существующий тенант | `subscription_status = active` в миграции/сидере |
| Управление доступом | Роль `super_admin` + панель `/admin/tenants` |

**Дефолты:**
- После регистрации — redirect на [`/settings`](../routes/web.php) (онбординг API-ключей)
- Super-admin в сидере: `super@crm.by / password`, `tenant_id = null`

## Архитектура

```mermaid
flowchart TD
    subgraph auth [Auth]
        Register[POST /register] --> CreateTenant[Create Tenant trial +14d]
        CreateTenant --> CreateAdmin[Create User admin]
        CreateAdmin --> SeedSettings[Seed tenant_settings]
        SeedSettings --> AutoLogin[Auth login]
    end

    subgraph guard [Access control]
        Req[HTTP Request] --> IsSuper{super_admin?}
        IsSuper -->|yes| AdminPanel[/admin/tenants]
        IsSuper -->|no| SetTenant[SetTenant middleware]
        SetTenant --> Safe{GET/HEAD?}
        Safe -->|yes| Allow[Allow]
        Safe -->|no| ReadOnly{tenant.isReadOnly?}
        ReadOnly -->|yes| Deny403[403 Read-only]
        ReadOnly -->|no| Allow
    end

    subgraph webhook [Webhook - no subscription check]
        WH[POST /api/webhook/lead] --> CreateOrder[Create Order always]
        CreateOrder --> CondSR{tenant writable?}
        CondSR -->|yes| SalesRender[SalesRender push]
        CondSR -->|no| SkipSR[Skip SR]
    end
```

## Затронутые файлы

| Файл | Изменение |
|------|-----------|
| [`database/migrations/`](../database/migrations/) | поля подписки в `tenants`; `super_admin` в `users.role`; nullable `tenant_id` |
| [`config/subscription.php`](../config/subscription.php) | `trial_days => 14` |
| [`app/Models/Tenant.php`](../app/Models/Tenant.php) | методы подписки |
| [`app/Models/User.php`](../app/Models/User.php) | роль `super_admin`, helpers |
| [`app/Services/TenantProvisioner.php`](../app/Services/TenantProvisioner.php) | дефолтные settings при создании тенанта |
| [`app/Http/Middleware/EnsureTenantWritable.php`](../app/Http/Middleware/EnsureTenantWritable.php) | блокировка мутаций в read-only |
| [`app/Http/Middleware/SetTenant.php`](../app/Http/Middleware/SetTenant.php) | skip для super_admin |
| [`app/Http/Kernel.php`](../app/Http/Kernel.php) | alias `tenant.writable` |
| [`app/Providers/AuthServiceProvider.php`](../app/Providers/AuthServiceProvider.php) | Gates super-admin |
| [`app/Http/Controllers/Auth/RegisterController.php`](../app/Http/Controllers/Auth/RegisterController.php) | self-registration |
| [`app/Http/Controllers/Auth/LoginController.php`](../app/Http/Controllers/Auth/LoginController.php) | redirect по роли |
| [`app/Http/Controllers/Admin/TenantController.php`](../app/Http/Controllers/Admin/TenantController.php) | управление тенантами |
| [`app/Http/Controllers/WebhookController.php`](../app/Http/Controllers/WebhookController.php) | SR guard для read-only |
| [`app/Console/Kernel.php`](../app/Console/Kernel.php) | cron только для writable tenants |
| [`app/Http/Middleware/HandleInertiaRequests.php`](../app/Http/Middleware/HandleInertiaRequests.php) | share `subscription` |
| [`routes/web.php`](../routes/web.php) | register, admin routes |
| [`database/seeders/DatabaseSeeder.php`](../database/seeders/DatabaseSeeder.php) | active tenant + super-admin |
| [`resources/js/Pages/Auth/Register.vue`](../resources/js/Pages/Auth/Register.vue) | форма регистрации |
| [`resources/js/Pages/Auth/Login.vue`](../resources/js/Pages/Auth/Login.vue) | ссылка на register |
| [`resources/js/composables/useSubscription.js`](../resources/js/composables/useSubscription.js) | readOnly, trialDaysLeft |
| [`resources/js/Components/TrialBanner.vue`](../resources/js/Components/TrialBanner.vue) | баннер trial/read-only |
| [`resources/js/Layouts/AppLayout.vue`](../resources/js/Layouts/AppLayout.vue) | подключение баннера |
| [`resources/js/Layouts/AdminLayout.vue`](../resources/js/Layouts/AdminLayout.vue) | layout super-admin |
| [`resources/js/Pages/Admin/Tenants/Index.vue`](../resources/js/Pages/Admin/Tenants/Index.vue) | таблица тенантов |

---

## 1. База данных

**Migration 1** — поля подписки в `tenants`:

```php
subscription_status  enum('trial','active','expired','suspended') default 'trial'
trial_ends_at        timestamp nullable
subscribed_at        timestamp nullable
```

**Migration 2** — расширить `users.role` (добавить `super_admin`); сделать `tenant_id` **nullable**.

**Data migration:**
- Все существующие тенанты → `active`, `subscribed_at = now()`
- Новые через registration → `trial`, `trial_ends_at = now()->addDays(14)`

---

## 2. Модели

### Tenant

- `isReadOnly(): bool` — `active` → false; `suspended`/`expired` → true; `trial` → true если `now >= trial_ends_at`
- `effectiveStatus(): string` — вычисляет `expired` для trial с прошедшей датой
- `trialDaysLeft(): ?int`
- `activate(): void` — status `active`, `subscribed_at = now()`
- `extendTrial(int $days): void` — сдвинуть `trial_ends_at`, status `trial`

### User

- `ROLES` → `['super_admin', 'admin', 'manager', 'operator']`
- `isSuperAdmin(): bool`
- `isTenantUser(): bool` — `tenant_id !== null`

---

## 3. TenantProvisioner

Вынести дефолтные `tenant_settings` из сидера в сервис:

```php
provision(Tenant $tenant, string $shopName = 'BaseCRM'): void
```

Использовать в `DatabaseSeeder` и `RegisterController`.

---

## 4. Middleware

### EnsureTenantWritable

- Пропускать `super_admin`
- Для tenant-пользователей: если `$user->tenant->isReadOnly()` и метод не safe → 403
- **Исключения:** `POST /logout`, `PATCH /settings/theme`

### SetTenant

```php
if (Auth::check() && Auth::user()->isTenantUser()) {
    app()->instance('current_tenant_id', Auth::user()->tenant_id);
}
```

### Применение `tenant.writable`

Контроллеры: `OrderController`, `BelpostController`, `EvropostController`, `ProductController`, `FinanceController`, `UserController`, `TenantSettingController` (кроме `updateTheme`), AJAX routes `tracking/auto-notice/dismiss`, `orders/refresh-tracking`.

Admin-контроллер — только `auth` + Gate `manage-tenants`.

---

## 5. Auth: регистрация

**GET `/register`** — Inertia `Auth/Register`

**POST `/register`** (guest, `throttle:6,1`):
- Validate: `company_name`, `name`, `email` (unique), `password` + confirmation (min 8)
- DB transaction: Tenant → User (admin) → TenantProvisioner
- `Auth::login($user)` → redirect `/settings`

**Login redirect:**

```php
return $user->isSuperAdmin()
    ? redirect()->intended('/admin/tenants')
    : redirect()->intended('/orders');
```

---

## 6. Super-admin панель

### Gates

```php
Gate::define('super-admin', fn ($u) => $u->isSuperAdmin());
Gate::define('manage-tenants', fn ($u) => $u->isSuperAdmin());
```

Существующие Gates — только tenant roles, **не** `super_admin`.

### Admin/TenantController

| Route | Action |
|-------|--------|
| `GET /admin/tenants` | Список: name, status, trial_ends_at, admin email, users/orders count |
| `PATCH /admin/tenants/{tenant}` | `{ subscription_status, trial_ends_at }` |
| `POST /admin/tenants/{tenant}/activate` | `$tenant->activate()` |
| `POST /admin/tenants/{tenant}/extend-trial` | `{ days: 14 }` |

---

## 7. Inertia shared state

```php
'subscription' => [
    'status'        => $tenant->effectiveStatus(),
    'readOnly'      => $tenant->isReadOnly(),
    'trialDaysLeft' => $tenant->trialDaysLeft(),
    'trialEndsAt'   => $tenant->trial_ends_at?->toIso8601String(),
],
'auth.user' => /* добавить isSuperAdmin flag */
```

---

## 8. Frontend: read-only UX

### TrialBanner

- Trial, days > 3: нейтральный («Осталось N дней»)
- Trial, days ≤ 3: предупреждающий
- Read-only: «Пробный период закончился. Доступен только просмотр»

### Disable mutating UI при `readOnly`

| Страница | Действия |
|----------|----------|
| `Orders/Index.vue` | refresh-tracking, inline edits |
| `Orders/Create.vue`, `Import.vue` | submit |
| `Orders/Show.vue` | status/delivery updates |
| `Belpost/Batch.vue` | все POST |
| `Europochta/Create.vue` | register |
| `Products/Index.vue` | CRUD |
| `Finance/Index.vue` | CRUD |
| `Users/Index.vue` | CRUD |
| `Settings/Index.vue` | save settings (theme — разрешена) |

---

## 9. Webhook и фоновые задачи

### WebhookController

- Без проверки подписки на вход
- SalesRender — только если `!$tenant->isReadOnly()`
- Blacklist-check оставить (часть intake)

### Console/Kernel

```php
Tenant::whereIn('subscription_status', ['trial', 'active'])
    ->get()
    ->filter(fn ($t) => !$t->isReadOnly())
```

Применить для: morning tracking, SalesRender dispatch, `SumOrdersCommand`.

---

## 10. Seeder

- Тестовый тенант: `subscription_status = active`, `subscribed_at = now()`
- Super-admin: `tenant_id = null`, `role = super_admin`, `super@crm.by`
- Использовать `TenantProvisioner`

---

## 11. Тесты

| Файл | Сценарии |
|------|----------|
| `tests/Unit/TenantSubscriptionTest.php` | `isReadOnly()`, `trialDaysLeft()`, `effectiveStatus()` |
| `tests/Feature/RegistrationTest.php` | POST /register создаёт tenant+trial+admin; duplicate email 422 |
| `tests/Feature/ReadOnlyAccessTest.php` | expired tenant: GET /orders 200, POST /orders 403 |
| `tests/Feature/WebhookReadOnlyTest.php` | expired tenant: webhook создаёт order, SR не вызывается |
| `tests/Feature/AdminTenantTest.php` | super_admin activate/extend; tenant admin → 403 на /admin |

---

## Порядок реализации

1. Migrations + config + модели `Tenant`/`User`
2. `TenantProvisioner` + обновление seeder
3. Middleware (`EnsureTenantWritable`, `SetTenant`) + Kernel + Gates
4. `RegisterController` + routes + Register/Login Vue
5. Inertia share + `TrialBanner` + composable
6. Read-only на ключевых Vue-страницах
7. `Admin/TenantController` + AdminLayout + Tenants/Index
8. Webhook SR guard + cron filter
9. Feature/Unit tests

---

## Acceptance Criteria

- [ ] `/register` создаёт tenant (`trial`, +14 дней) + user (`admin`) + default settings
- [ ] Email уникален глобально
- [ ] После истечения trial: mutating-запросы → 403
- [ ] GET-маршруты (списки, детали, PDF) работают
- [ ] Webhook `/api/webhook/lead` создаёт заказы для любого статуса
- [ ] Сидер: тестовый тенант → `active`
- [ ] Super-admin видит всех тенантов и меняет `subscription_status`, `trial_ends_at`
- [ ] Super-admin не попадает под `TenantScope` при управлении платформой
- [ ] Cron/jobs для read-only тенантов не мутируют данные

---

## Риски

- **Route model binding:** tenant-user не должен видеть чужие записи (уже через `TenantScope` + `assertSameTenant`)
- **Super-admin без tenant_id:** redirect super_admin на `/admin/tenants` при заходе на `/orders`
- **MySQL enum alter:** migration должна корректно расширить enum `role` на MySQL 5.7 (raw SQL `ALTER TABLE ... MODIFY`)
