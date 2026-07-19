# Документация по хостингу BaseCRM

> **Примечание:** часть документов ссылается на legacy-код GAS (`../../backend/`) и общую документацию CRM (`../../docs/`). Эти пути работают при checkout monorepo `CRM/` на диске, но не входят в git-репозиторий `hosting`.

Материалы по развёртыванию CRM на белорусском хостинге (hoster.by) с учётом требований к хранению персональных данных.

## Документы

| Файл | Описание |
|------|----------|
| [migration-plan.md](./migration-plan.md) | Полный план миграции GAS → Laravel + Vue + MySQL: стек, архитектура, БД, webhook, фазы |
| [features/manual-order-create.md](./features/manual-order-create.md) | Ручное создание заказа (Вариант B): маршруты, контроллер, Create.vue |

## Ключевые решения

- **Хостинг:** hoster.by, тариф «Хостинг для персональных данных»
- **Стек:** PHP 7.4, Laravel 8, Inertia.js, Vue 3, MySQL 5.7
- **Данные:** только в РБ; без Google Sheets и зарубежных PaaS для prod
- **Заявки с сайта:** `POST /api/webhook/lead` (замена GAS `doPost`)
