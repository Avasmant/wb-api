# WB API → MySQL

Сервис на **Laravel 13 / PHP 8.3** стягивает данные тестового WB API
(`incomes`, `orders`, `sales`, `stocks`) и сохраняет их в **MySQL** с идемпотентным
upsert (повторный запуск не плодит дубли).

---

## Доступы к БД

> Заполняется реальными значениями после создания базы. Эти же значения лежат в `.env` на сервере.

| Параметр            | Значение                          |
|---------------------|-----------------------------------|
| Тип СУБД            | MySQL (Percona 8.0)               |
| Хост                | `__DB_HOST__`                     |
| Порт                | `3306`                            |
| База данных         | `__DB_DATABASE__`                 |
| Пользователь        | `__DB_USERNAME__`                 |
| Пароль              | `__DB_PASSWORD__`                 |

Подключение для проверки:
```bash
mysql -h __DB_HOST__ -P 3306 -u __DB_USERNAME__ -p __DB_DATABASE__
```

---

## Таблицы

В базе создаются 4 целевые таблицы (плюс служебная `migrations`):

| Таблица    | Источник (эндпоинт)      | Назначение                                   |
|------------|--------------------------|----------------------------------------------|
| `incomes`  | `GET /api/incomes`       | Поставки (приёмки) на склады WB              |
| `orders`   | `GET /api/orders`        | Заказы                                       |
| `sales`    | `GET /api/sales`         | Продажи / возвраты                           |
| `stocks`   | `GET /api/stocks`        | Остатки на складах (срез на текущий день)    |

Поля таблиц 1:1 повторяют поля API (snake_case). У каждой таблицы есть:
- `id` — суррогатный PK;
- `uniq_hash` (UNIQUE) — md5 натурального ключа строки, по нему идёт upsert;
- `created_at` / `updated_at`.

Натуральный ключ (что считается «той же» записью):
- **incomes** — `income_id + nm_id + barcode + tech_size + date + warehouse_name`;
- **orders** — `g_number + odid + nm_id + barcode + date`;
- **sales** — `sale_id` (если пуст — `g_number + date + barcode + nm_id`);
- **stocks** — `date + barcode + nm_id + tech_size + warehouse_name`.

---

## Структура кода

```
app/
  Models/            Income, Order, Sale, Stock — Eloquent-модели
  Services/
    WbApiClient.php  HTTP-клиент WB API (пагинация через генератор)
  Console/Commands/
    WbSyncCommand.php  команда wb:sync (маппинг + upsert)
config/
  wb.php             base URL, key, limit, дата старта, timeout
database/
  migrations/        4 миграции целевых таблиц
```

---

## Установка и запуск

```bash
# 1. зависимости
composer install

# 2. окружение
cp .env.example .env
php artisan key:generate
# прописать в .env доступы к MySQL и параметры WB API (см. ниже)

# 3. схема БД
php artisan migrate

# 4. загрузка данных
php artisan wb:sync                 # все 4 эндпоинта
php artisan wb:sync orders          # только заказы
php artisan wb:sync sales --from=2024-01-01 --to=2026-06-30
php artisan wb:sync stocks          # остатки на сегодня
```

Переменные окружения (`.env`):
```
WB_API_BASE=http://109.73.206.144:6969/api
WB_API_KEY=<секретный ключ>
WB_API_LIMIT=500
WB_API_DATE_FROM=2020-01-01

DB_CONNECTION=mysql
DB_HOST=__DB_HOST__
DB_PORT=3306
DB_DATABASE=__DB_DATABASE__
DB_USERNAME=__DB_USERNAME__
DB_PASSWORD=__DB_PASSWORD__
```

---

## Как работает синхронизация

1. `WbApiClient::paginate()` идёт по страницам эндпоинта (`limit=500`), отдавая их генератором — в памяти максимум одна страница.
2. `WbSyncCommand` маппит каждую строку API в колонки таблицы, нормализует типы
   (даты, числа, булевы, пустые строки → `NULL`) и считает `uniq_hash`.
3. Страница пишется в БД через `upsert(... , ['uniq_hash'], ...)` —
   новые строки вставляются, существующие обновляются. Запись разбивается на под-батчи
   под лимит плейсхолдеров СУБД.

Эндпоинт `stocks` принимает только `dateFrom` и отдаёт срез на текущий день,
поэтому команда всегда запрашивает его на сегодняшнюю дату.

---

## Объёмы данных (на момент загрузки)

| Эндпоинт | Записей в источнике |
|----------|---------------------|
| incomes  | ~2 900              |
| orders   | ~149 000            |
| sales    | ~135 000            |
| stocks   | ~3 700 (на день)    |
