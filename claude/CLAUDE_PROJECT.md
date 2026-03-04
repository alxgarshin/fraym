# Fraym Framework — Контекст для Claude

Fraym — boxed-решение для ERP-систем. Цель: минимум бойлерплейта, максимальная скорость разработки бизнес-модулей. Включает PHP-бэкенд (пакет `fraym/fraym`) и JS/CSS фронтенд-рантайм (`skeleton/public/vendor/fraym/`).

---

## Структура проекта

```
fraym/
├── src/                          # Ядро фреймворка (пакет fraym/fraym)
│   ├── BaseObject/               # Базовые классы и трейты CMSVC
│   ├── Container.php             # Service Container (bind/make/reset)
│   ├── Kernel.php                # Bootstrap: env → константы → сервисы
│   ├── Entity/
│   │   └── BaseEntity.php        # Ядро персистентности и CRUD (1500+ строк)
│   ├── Element/
│   │   ├── Attribute/            # PHP 8 Attributes (метаданные полей)
│   │   └── Item/                 # Runtime-экземпляры полей (get/set/asHTML/asArray)
│   ├── DatabaseDialect/          # MySQLDialect, PostgreSQLDialect (Strategy Pattern)
│   ├── Enum/                     # ActionEnum, RequestTypeEnum, DbTypeEnum, ...
│   ├── Exception/                # DatabaseException, DatabaseConnectionException, DatabaseQueryException
│   ├── Helper/                   # AuthHelper, ResponseHelper, CookieHelper, DataHelper, ...
│   ├── Interface/                # Database, DatabaseDialect, ...
│   └── Service/                  # SQLDatabaseService, CacheService, EnvService, ...
└── skeleton/                     # Эталонный проект на базе фреймворка
    ├── public/
    │   ├── index.php             # Точка входа: require fraym.php + include src/index.php
    │   ├── fraym.php             # Autoload + Kernel::init() + exception handlers
    │   └── vendor/fraym/         # Фронтенд фреймворка (JS/CSS рантайм)
    │       ├── js/global.js      # Ядро SPA (5000+ строк)
    │       ├── css/global.css    # Базовые стили
    │       ├── cmsvc/js.php      # Lazy-загрузчик JS по KIND
    │       ├── cmsvc/css.php     # Lazy-загрузчик CSS по KIND
    │       ├── locale/           # Фронтенд-локали (RU/EN/ES)
    │       └── js/               # Vendored: filepond, quill, noty, modal, tabs, ...
    └── src/
        ├── index.php             # Роутер + диспетчер ответов
        └── CMSVC/                # Модули приложения
            └── {Kind}/           # Каждый модуль = папка
                ├── {Kind}Controller.php
                ├── {Kind}Model.php    (опционально)
                ├── {Kind}View.php     (опционально)
                ├── {Kind}Service.php  (опционально)
                ├── js.js / js.min.js
                ├── css.css / css.min.css
                └── RU.json / EN.json / ES.json
```

---

## CLI-инструмент (Console)

`src/Console.php` — встроенный CLI. Точка входа: `./vendor/bin/console`.

### Команды

| Команда | Описание |
|---------|---------|
| `install` | Копирует skeleton-проект в текущую директорию (не перезаписывает существующие файлы) |
| `install:force` | То же, но перезаписывает существующие файлы |
| `make:cmsvc --cmsvc=ObjectName` | Генерирует полный CMSVC-модуль: Controller, Model, Service, View, JS, CSS, EN.json, RU.json |
| `make:migration` | Генерирует скелет Migration + Fixture + SQL-файл с timestamp-именем |
| `database:migrate` | Применяет все непримененные миграции (up) |
| `database:migrate:up` | То же |
| `database:migrate:down --migration=XXXXXXXX` | Откатывает конкретную миграцию |
| `database:migrate --migration=XXXXXXXX` | Применяет конкретную миграцию |
| `database:drop` | Удаляет БД (только `APP_ENV=DEV` или `TEST`) |

### Флаги

| Флаг | Описание |
|------|---------|
| `--cmsvc=ObjectName` | Имя модуля (camelCase или snake_case — конвертируется автоматически) |
| `--migration=20230627140700` | Имя миграции (с или без `Migration` префикса, с или без `.php`) |
| `--env=test` | Использует `.env.test` для подключения к тестовой БД |

### Поведение
- `make:cmsvc` не перезаписывает существующие файлы — безопасно запускать повторно
- `database:migrate` в DEV/TEST окружении: автоматически создаёт БД и пользователя, если они не существуют
- `database:drop` — только DEV/TEST. В stage/prod — запрещено
- Fixtures запускаются автоматически после `up`-миграции в DEV/TEST окружении
- Для работы с БД Console использует `MIGRATE_DB` (отдельное соединение от `DB`)

### Цветной вывод
- Зелёный — успех
- Жёлтый — предупреждение (файл уже существует)
- Красный — ошибка

---

## Bootstrap-цепочка

```
HTTP-запрос
  → public/index.php
    → require fraym.php
      → vendor/autoload.php
      → set_exception_handler(...)        # глобальный catch-all → HTTP 500
      → try { Kernel::init() }            # загрузка env, инициализация сервисов
        catch (DatabaseConnectionException) → HTTP 503
    → include src/index.php              # роутинг, контроллер, dispatch ответа
```

### Порядок загрузки .env файлов (Kernel::init)
1. `.env.fraym` — настройки фреймворка
2. `.env` — настройки проекта
3. `.env.dev` | `.env.stage` | `.env.prod` — окружение (первый найденный)

Каждый последующий файл перекрывает предыдущий.

---

## Глобальные константы (доступны везде после Kernel::init)

| Константа       | Тип                    | Источник                                  |
|-----------------|------------------------|-------------------------------------------|
| `DB`            | `DatabaseProxy`        | делегирует в `Container::make('db')`      |
| `CACHE`         | `CacheProxy`           | делегирует в `Container::make('cache')`   |
| `CURRENT_USER`  | `CurrentUserProxy`     | делегирует в `Container::make('current_user')` |
| `ACTION`        | `?ActionEnum`          | `$_REQUEST['action']`                     |
| `KIND`          | `string`               | `$_REQUEST['kind']` или `STARTING_KIND`   |
| `CMSVC`         | `string`               | `$_REQUEST['cmsvc']` или `KIND`           |
| `ID`            | `array\|null`          | `$_REQUEST['id']` (нормализован в массив) |
| `REQUEST_TYPE`  | `RequestTypeEnum`      | Определяется по заголовкам запроса        |
| `ABSOLUTE_PATH` | `string`               | `$_ENV['ABSOLUTE_PATH']`                  |
| `GLOBALTIMER`   | `GlobalTimerService`   | —                                         |

### Proxy-паттерн для констант (src/Proxy/)

Константы `DB`, `CACHE`, `CURRENT_USER` — это прокси-объекты, а не сами сервисы.
Прокси делегирует каждый вызов в `Container::make(id)` в момент вызова.

**Это позволяет:**
- Подменять реализацию в тестах: `Container::bind('db', $mockDb)` — и `DB->select()` пойдёт в mock
- Поддерживать persistent workers: `Container::reset()` + `Container::bind(...)` пересоздают сервис, прокси-константа остаётся прежней
- Весь вызывающий код (`DB->select(...)`) не меняется

```php
// Тест с моком:
Container::bind('db', $mockDb);
DB->select(...);  // → $mockDb->select(...)

// Persistent worker между запросами:
Container::reset();
Container::bind('db', SQLDatabaseService::forceCreate());
Container::bind('current_user', CurrentUser::forceCreate());
Container::bind('cache', CacheService::forceCreate());
// DB / CURRENT_USER / CACHE константы всё ещё работают через прокси
```

**Интерфейсы:**
- `src/Interface/Database.php` — реализуют `SQLDatabaseService` и `DatabaseProxy`
- `src/Interface/Cache.php` — реализуют `CacheService` и `CacheProxy`
- `src/Interface/CurrentUser.php` — реализуют `CurrentUser` и `CurrentUserProxy`

### Container (src/Container.php)
```php
Container::bind('db', $instance);   // регистрация
Container::make('db');              // получение
Container::reset();                 // сброс всех биндингов
```

---

## Типы запросов (RequestTypeEnum)

| Тип                   | Условие                              | Метод                    |
|-----------------------|--------------------------------------|--------------------------|
| `FRAYM_REQUEST`       | `HTTP_FRAYM_REQUEST: true`           | SPA-навигация, cookie-auth |
| `FRAYM_API_REQUEST`   | `HTTP_FRAYM_API_REQUEST: true`       | Bearer-token API         |
| `HTMX_REQUEST`        | `HTTP_HX_REQUEST: true`             | HTMX                     |
| `NOT_A_DYNAMIC_REQUEST` | default                            | Полная загрузка страницы |

```php
REQUEST_TYPE->isApiRequest()      // только FRAYM_API_REQUEST
REQUEST_TYPE->isDynamicRequest()  // всё кроме NOT_A_DYNAMIC_REQUEST
```

---

## CMSVC-паттерн

Аббревиатура: **C**ontroller → **M**odel → **S**ervice → **V**iew → **C**ontext.

Связывание слоёв через PHP 8 Attribute `#[CMSVC(...)]` или `#[Controller(...)]`:

```php
#[CMSVC(
    controller: NewsEditController::class,
    model:      NewsEditModel::class,
    service:    NewsEditService::class,   // опционально
    view:       NewsEditView::class,
    objectName: 'news',                   // имя таблицы / объекта
)]
class NewsEditModel extends BaseModel { ... }
```

### Двухфазная инициализация
Все CMSVC-объекты используют `construct()` вместо `__construct()`:
```php
$controller = new NewsEditController();
$controller->construct(CMSVCinit: false); // только конструктор
$controller->CMSVC->init();              // полная инициализация CMSVC-цепочки
```
Последующие записи создаются через `clone $templateModel` — дешевле, чем новый `construct()`.

### Роутинг (skeleton/src/index.php)
URL `/{kind}/action={action}` → `App\CMSVC\{Kind}\{Kind}Controller`.

Для кастомного метода контроллера: `$controller->{ACTION}()`.

---

## ValueObject bridge: Element\Attribute → Element\Item

**Attribute** (метаданные, PHP 8 Attribute) — описывает поле: тип, контекст, валидация, отображение.
**Item** (runtime-экземпляр) — хранит значение, умеет `get()`, `set()`, `asHTML()`, `asArray()`.

Преобразование имени класса:
```php
$itemClass = str_replace('\Attribute\\', '\Item\\', $attributeClass);
// Fraym\Element\Attribute\Text → Fraym\Element\Item\Text
```

Доступные типы: `Text`, `Textarea`, `Number`, `Email`, `Password`, `Login`, `Hidden`,
`Checkbox`, `Select`, `Multiselect`, `File`, `Wysiwyg`, `Timestamp`, `H1`, `Tab`.

### Объявление полей в модели
```php
#[Attribute\Text(
    obligatory: true,
    context: ['news:list', 'news:create', 'news:update'],
    minChar: 3,
    maxChar: 255,
)]
public Item\Text $title;
```

### OnCreate / OnChange
```php
#[Attribute\Hidden]
#[Attribute\OnCreate(callback: 'getCreator')]  // вызывается при создании
#[Attribute\OnChange(callback: 'getTime')]      // вызывается при изменении
public Item\Hidden $creator_id;
```

---

## Контекст-система

Контексты — строковые теги формата `objectName:suffix`. Управляют видимостью полей и режимом рендеринга.

Каждое поле в своём Attribute-объявлении содержит массив `context`. При фильтрации фреймворк проверяет, есть ли хотя бы одно совпадение между этим массивом и строками соответствующего ключа CMSVC-контекста.

### Полное дерево контекстов (CMSVC::init / BaseElement::getContext)

| Ключ (константа) | Строки, входящие в ключ               |
|------------------|---------------------------------------|
| `LIST`           | `:list`, `objectName:list`            |
| `VIEW`           | `:view`, `objectName:view`            |
| `VIEWIFNOTNULL`  | `:viewIfNotNull`, `objectName:viewIfNotNull` |
| `VIEWONACTADD`   | `:viewOnActAdd`, `objectName:viewOnActAdd`   |
| `CREATE`         | `:create`, `objectName:create`        |
| `UPDATE`         | `:update`, `objectName:update`        |
| `EMBEDDED`       | `:embedded`, `objectName:embedded`    |

Каждый ключ принимает обе формы: `:list` — универсальный (работает для любого объекта), `objectName:list` — специфичный. В полях оба варианта равнозначны.

`objectName` в контексте всегда в нижнем регистре (`mb_lcfirst`): например, для `NewsEditModel` это `newsEdit`.

Строка `:delete` используется в полях (например, в `IdTrait`) как маркер видимости при операции удаления, но не является отдельным ключом в CMSVC-контекстном дереве — обрабатывается напрямую в `fraymAction`.

### CatalogEntity
Для модулей, основанных на `CatalogEntity`, в `CMSVC::init()` дополнительно добавляются строки с именем `catalogItemEntity` в каждый ключ (LIST, VIEW, VIEWIFNOTNULL, CREATE, UPDATE, EMBEDDED).

### Рекомендованный стиль — `const CONTEXT` в модели
```php
class NewsEditModel extends BaseModel {
    const CONTEXT = [
        'list'   => 'newsEdit:list',
        'create' => 'newsEdit:create',
        'update' => 'newsEdit:update',
    ];
}
```

---

## BaseModel lifecycle

```
construct()                    # reflection → кэш → initElement() для каждого property
  └─ static $propertyCache     # кэш метаданных по имени класса (persist workers)
       └─ clone $attribute      # при каждом initElement — клон, не ссылка

clone $model                   # __clone() клонирует каждый element в elementsList
  └─ $element->model = $this   # обновляет обратную ссылку
```

### Стандартные трейты для моделей

| Трейт                    | Добавляет                            |
|--------------------------|--------------------------------------|
| `IdTrait`                | `$id` (Hidden, obligatory, noData, context: `:list`, `:update`, `:delete`) |
| `CreatorIdTrait`         | `$creator_id` (Hidden, OnCreate → CURRENT_USER->id()) |
| `CreatedUpdatedAtTrait`  | `$created_at`, `$updated_at` (Timestamp, OnCreate/OnChange) |
| `LastUserUpdateIdTrait`  | `$last_user_update_id`               |
| `DeletedAtTrait`         | Soft delete через `$deleted_at`      |

### DependencyInjection в CMSVC-объектах
```php
#[DependencyInjection]
public SomeService $someService;   // автоматически инжектируется через рефлексию
```

---

## BaseEntity — ключевые точки входа

- **`fraymAction()`** — gateway для create/change/delete. Проверяет права → CSRF → запускает lifecycle.
- **`filterDataByContext(string $context)`** — фильтрует fields по контексту для HTML/API.
- **`asHTML(?array $data)`** → `HtmlResponse`
- **`asArray(?array $data)`** → `ArrayResponse`

### CSRF-проверка в fraymAction (добавлена)
```php
if (!REQUEST_TYPE->isApiRequest() && !AuthHelper::validateCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    ResponseHelper::response403();
}
```
Пропускается для: Bearer-token (API) запросов, незалогиненных пользователей.

---

## Аутентификация

- **JWT** (1ч, HS256) + **refresh token** (30д, хранится в БД)
- Cookies: `secure=true`, `httponly=true`, `samesite=Strict`
- Пароли: Argon2ID с pepper (`PROJECT_HASH_WORD`)
- CSRF-токен: stateless HMAC-SHA256 от `userId:sid:dailyNonce` на `PROJECT_HASH_WORD`

```php
AuthHelper::generateCsrfToken()          // для инжекта в HTML → window["csrfToken"]
AuthHelper::validateCsrfToken($token)    // принимает сегодня и вчера (стык суток)
```

---

## ResponseHelper — ключевые методы

```php
ResponseHelper::response401()                     # exit с HTTP 401
ResponseHelper::response403()                     # exit с HTTP 403 (CSRF)
ResponseHelper::response(messages, redirectPath)  # ArrayResponse
ResponseHelper::responseOneBlock(type, message)   # exit с JSON
ResponseHelper::redirect(link)                    # redirect
ResponseHelper::setCorsHeaders()                  # по ALLOWED_ORIGINS из env
```

### CORS
Управляется через `ALLOWED_ORIGINS` в `.env`:
- Пусто → заголовок не выставляется
- `*` → `Access-Control-Allow-Origin: *` (только в `.env.dev`)
- `https://app.example.com` → проверяет `HTTP_ORIGIN`, выставляет `Vary: Origin`

---

## Исключения БД

```
DatabaseException (RuntimeException)
├── DatabaseConnectionException   # ошибка соединения → поймать → HTTP 503
└── DatabaseQueryException        # ошибка prepare/execute → глобальный handler → HTTP 500
```

---

## MySQL / PostgreSQL совместимость — DatabaseDialect

Весь диалект-специфичный SQL инкапсулирован в **Strategy Pattern**: `src/Interface/DatabaseDialect.php` + два класса в `src/DatabaseDialect/`. Клиентский код использует `DB->dialect->method()` — **никаких** `if DATABASE_TYPE` за пределами трёх легитимных мест.

### Использование в коде

```php
// Правильно — через диалект:
$sign = DB->dialect->getNullSafeEqualOperator();           // '<=>' или '='
$query .= DB->dialect->getInsertReturningClause('id');    // ' RETURNING id' или ''
$sign = DB->dialect->getGroupFieldQuerySign();             // '\"' или '"'

// Получить ORDER BY для пользовательских значений:
['selectExtra' => $extra, 'orderBy' => $orderBy] =
    DB->dialect->orderByCustomValuesSql('type', $types, 'tie_field');
// MySQL → FIELD(type, ...) / PostgreSQL → CASE WHEN ... END
```

### Интерфейс DatabaseDialect — все 17 методов

| Метод | Назначение |
|---|---|
| `getDsnOptions()` | DSN-суффикс (`;charset=utf8mb4` / `''`) |
| `getNullSafeEqualOperator()` | `<=>` / `=` для NULL-safe WHERE |
| `getInsertReturningClause(field)` | `RETURNING field` / `''` после INSERT |
| `extractLastInsertId(result)` | ID из RETURNING-результата; `null` = использовать PDO::lastInsertId() |
| `getGroupFieldQuerySign()` | `\"` / `"` для LIKE-поиска в JSON-группах |
| `terminateConnectionsSql(db)` | `pg_terminate_backend(...)` / `null` |
| `checkDatabaseExistsSql(db)` | `SHOW DATABASES LIKE` / `SELECT FROM pg_database` |
| `useDatabaseSql(db)` | `USE db;` / `null` |
| `checkUserExistsSql(user)` | `mysql.user` / `pg_roles` |
| `createUserSql(...)` | `CREATE USER` синтаксис для каждой СУБД |
| `alterUserSql(...)` | `ALTER USER` синтаксис для каждой СУБД |
| `createDatabaseOwnerSuffix(user)` | `''` / ` OWNER user` |
| `grantPrivilegesSql(...)` | `GRANT ALL ON db.*` / `GRANT ALL ON DATABASE db` |
| `afterGrantSql()` | `FLUSH PRIVILEGES` / `null` |
| `createMigrationTableSql()` | DDL таблицы migration (AUTO_INCREMENT vs UUID) |
| `setTimezoneSql()` | `SET time_zone='+03:00'` / `SET TIME ZONE 'Europe/Moscow'` |
| `orderByCustomValuesSql(field, values, tie)` | `FIELD(...)` / `CASE WHEN ... END` |

### Легитимные остатки DATABASE_TYPE (не трогать)

| Место | Причина |
|---|---|
| `DbTypeEnum.php` | Инициализация enum через `tryFrom($_ENV['DATABASE_TYPE'])` |
| `SQLDatabaseService.php` | PDO DSN-префикс: `pgsql:host=...` / `mysql:host=...` |
| `SqlTrait.php` | Выбор файла миграции: `.mysql.sql` vs `.sql` |

### DbTypeEnum — отдельный слой (не дублировать в диалекте)

`DbTypeEnum::quoteIdentifier()` и `DbTypeEnum::getRegexpWords()` уже инкапсулированы в enum и вызываются через `DB->dbType->...`. Это слой работы с идентификаторами — он не пересекается с `DatabaseDialect`.

### Файлы миграций — соглашение по именованию

```
src/Migrations/Sql/
├── SqlMigrationXXXXX.sql          # PostgreSQL DDL (основной)
└── SqlMigrationXXXXX.mysql.sql    # MySQL DDL (опциональный, если DDL различается)
```

`SqlTrait::getSql()` автоматически выбирает `.mysql.sql` при `DATABASE_TYPE=mysql`
(если файл существует), иначе fallback на `.sql`.

**При создании новой миграции с DDL:** создавать оба файла. MySQL DDL использует
`int AUTO_INCREMENT`, backtick-идентификаторы, `ENGINE=InnoDB`. PostgreSQL — UUID,
`gen_random_uuid()`, двойные кавычки.

---

## Фронтенд-рантайм

> Подробная документация — в `CLAUDE_PROJECT_FRONTEND.md`.

Fraym SPA построен на собственном JS-рантайме (`public/vendor/fraym/js/global.js`) без React/Vue/Angular.

**Ключевые механизмы:**
- `_()` — jQuery-подобный враппер с кэшированием (FraymElement)
- `updateState(href)` — SPA-навигация: заменяет `div.maincontent_data`, вызывает `fraymInit(false)`
- `actionRequest(params, target)` — паттерн для submit-действий (не навигационных), dispatch через `actionRequestCallbacks.success/error[action]`
- `fraymInit(withDocumentEvents)` — реинициализация компонентов; `true` только при первом запуске
- Lazy-загрузка по модулям: `cmsvc/js.php` → `dataLoaded.js[kind]`, `cmsvc/css.php` → CSS раздела
- `window.fetch` Proxy: автообновление JWT; `fetchData` добавляет `Fraym-Request: true` + CSRF-токен

**Встроенные UI-компоненты:** Modal, Tabs, Noty, Quill (WYSIWYG), FilePond (upload), Autocomplete, Dragdrop, Audioplayer, Styler, SBI (SVG inline).

---

## Структура CMSVC-модуля в skeleton

```
src/CMSVC/NewsEdit/
├── NewsEditController.php   # extends BaseController, #[CMSVC(...)]
├── NewsEditModel.php        # extends BaseModel, поля через Attribute + Item
├── NewsEditView.php         # extends BaseView, методы рендеринга
├── NewsEditService.php      # extends BaseService (опционально)
├── js.js / js.min.js        # JS-логика модуля
├── css.css / css.min.css    # Стили модуля
└── RU.json / EN.json / ES.json   # Переводы
```

### Минимальная модель
```php
#[CMSVC(controller: FooController::class, objectName: 'foo')]
class FooModel extends BaseModel {
    use IdTrait;
    use CreatedUpdatedAtTrait;
    use CreatorIdTrait;

    #[Attribute\Text(obligatory: true, context: ['foo:list', 'foo:create', 'foo:update'])]
    public Item\Text $name;
}
```

---

## BaseController — жизненный цикл

`Response()` — точка входа обработки запроса:
1. Если ACTION — встроенное (create/change/delete/setFilters/clearFilters) → `fraymAction()` или фильтры
2. Если ACTION — строка метода контроллера → `$this->{ACTION}()`
3. Иначе → `$this->Default()`

**Атрибуты доступа:**
```php
#[IsAccessible]  // требует авторизации; проверяется через checkIfIsAccessible()
#[IsAdmin]       // проверяется через checkIfHasToBeAndIsAdmin()
```

**Роутинг в skeleton/src/index.php — критичный порядок:**
```
1. new {Kind}Controller()
2. construct(CMSVCinit: false)    // только конструктор — без загрузки моделей/БД
3. checkIfIsAccessible()          // проверяем права ДО инициализации CMSVC
4. $controller->CMSVC->init()    // только теперь: загружаем модель/сервис/вьюшку
5. Response() / {ACTION}()
```

**BaseHelper:** контроллеры, наследующие `BaseHelper` (статические утилиты), не требуют CMSVC и всегда доступны без авторизации.

---

## BaseService — lifecycle hooks

Callback-атрибуты читаются из рефлексии и хранятся как строки имён методов сервиса:

```php
#[PreCreate(callback: 'beforeSave')]
#[PostCreate(callback: 'afterSave')]
#[PreChange(callback: 'beforeUpdate')]
#[PostChange(callback: 'afterUpdate')]
#[PreDelete(callback: 'beforeDelete')]
#[PostDelete(callback: 'afterDelete')]
class NewsEditService extends BaseService { ... }
```

Вызываются внутри `fraymAction()`: `$service->{$service->preCreate}()`.

**Ключевые методы сервиса:**

| Метод | Описание |
|-------|---------|
| `get(id, criteria, order, refresh, strict)` | Одна модель; `strict=true` → исключение если не ровно одна запись |
| `getAll(criteria, refresh, order, limit, offset)` | Возвращает **Generator** — ленивая итерация |
| `arrayToModel(data, refresh)` | Массив БД → модель; вызывает `detectModelTemplateBasedOnData()` |
| `postModelInit(model)` | Хук после полной инициализации модели |
| `preLoadModel()` | Вызывается контроллером перед fraymAction — для подмены модели |

`getAll()` возвращает Generator. Нельзя итерировать дважды. Каждый элемент создаётся через `clone $templateModel`.

---

## fraymAction — структура данных

`$_REQUEST` содержит **массивы** — одна операция может обрабатывать несколько строк одновременно:
```
$_REQUEST['name'][0] = "John"   $_REQUEST['id'][0] = 1
$_REQUEST['name'][1] = "Jane"   $_REQUEST['id'][1] = 2
```

`fraymAction()` итерирует строки. Ошибки собираются в `troubledStrings` (индекс строки) и `troubledElements` (имя элемента) — возвращаются в JSON-ответе, UI подсвечивает поля.

**OnCreate/OnChange трансформируют значения** перед сохранением через метод сервиса/модели, указанный в атрибуте.

---

## BaseModel — дополнительные возможности

**`getValues()` в Select/Multiselect может быть строкой имени метода:**
```php
#[Attribute\Select(values: 'getStatusOptions')]
public Item\Select $status;
// → автоматически вызывается $service->getStatusOptions() или $this->getStatusOptions()
```

**Управление элементами:**
```php
$model->getElement('name');
$model->removeElement('name');
$model->changeElementsOrder('name', 'beforeElement');
```

---

## SQLDatabaseService — полный API

### query() — универсальный исполнитель
```php
DB->query(
    '?string $query',
    array $data,      // array<int, array{0: string, 1: mixed, 2?: ?array<OperandEnum>}>
    bool $oneResult = false,
): false|array
```

`$data` — массив триплетов `[fieldName, value, ?[OperandEnum]]`. Если `value` — массив и нет `OperandEnum::JSON`, автоматически разворачивается в `IN (:name0, :name1, ...)`.

### constructWhere() — форматы criteria

```php
// Ассоциативный ключ → null-safe equal (<=>)
['field' => 'value']

// Ассоциативный ключ + массив значений → IN
['field' => ['a', 'b', 'c']]

// Индексированный без операнда → null-safe equal
[['field', 'value']]

// Индексированный с операндом
[['field', '%val%', [OperandEnum::LIKE]]]
[['field', 5,      [OperandEnum::LESS]]]
[['field', null,   [OperandEnum::IS_NULL]]]   // без :param placeholder
[['field', null,   [OperandEnum::NOT_NULL]]]
[['field', 'val',  [OperandEnum::LOWER]]]     // LOWER(field) <=> :field

// Одно поле дважды — авто-суффикс _0, _1
[['created_at', '2024-01-01', [OperandEnum::MORE]], ['created_at', '2024-12-31', [OperandEnum::LESS]]]
```

**OperandEnum:** `LIKE`, `NOT_LIKE`, `LESS`, `MORE`, `LESS_OR_EQUAL`, `MORE_OR_EQUAL`, `NOT_EQUAL`, `IS_NULL`, `NOT_NULL`, `JSON`, `LOWER`, `UPPER`.

### select()
```php
DB->select(
    tableName: 'news',
    criteria: ['deleted_at' => null, ['status', 'active']],
    oneResult: false,
    order: ['created_at DESC', 'id ASC'],  // массив строк, соединяются через ", "
    limit: 10,
    offset: 0,
    onlyCount: false,
    fieldsSet: ['id', 'title'],            // null → SELECT *
);
```

### insert() / update() / delete()
```php
// insert — data: ['field' => value] или [['field', value, ?params]]
// returningIdFieldName — имя PK-поля (по умолчанию 'id'); используется в RETURNING-clause (PostgreSQL)
DB->insert('news', ['title' => 'Text', 'author_id' => $id]);
DB->insert('news', ['title' => 'Text'], returningIdFieldName: 'uuid');
DB->lastInsertId();  // корректно работает для PostgreSQL (RETURNING) и MySQL

// update
DB->update('news', ['title' => 'New'], criteria: ['id' => $id]);

// delete
DB->delete('news', criteria: ['id' => $id]);
```

### Прочие полезные методы

| Метод | Назначение |
|-------|-----------|
| `findObjectById(objId, objType, refresh, bySid)` | Одна запись по id; CACHE-aware; `bySid` — искать по полю `sid` вместо `id` |
| `findObjectsByIds(objIds, objType, refresh)` | Generator; частичный hit из CACHE |
| `rowCount()` | Количество строк, затронутых последней операцией (INSERT/UPDATE/DELETE) |
| `selectCount()` | COUNT(*) по **последнему** запросу (повторно использует lastQuery['data']) |
| `count(tableName, criteria)` | Простой COUNT с criteria |
| `getArrayOfItems(fromClause, id, fields, nodata)` | Generator `[id => [id, label, level, ?data]]`; `fromClause` — строка после FROM |
| `getArrayOfItemsAsArray(...)` | То же, но `iterator_to_array` |
| `getTreeOfItems(empty, table, where, whereequal, and, order, level, id, fieldName, maxlevel, nodata, andQueryParams)` | Строит плоский массив иерархии с полем `level`; `whereequal` — `string\|int\|null`; `andQueryParams` — PDO-параметры для `$and`-условия |
| `chopOffTreeOfItemsBranches(...)` | Обрезает дерево — оставляет только нужные ветки с родителями |
| `beginTransaction()` / `commit()` / `rollBack()` | Транзакции |
| `exec(SQL)` | Сырое исполнение без prepare (только для миграций) |

Prepared statements кэшируются по SHA-хэшу запроса в `$preparedQueriesCache`.

> **Приватные методы сервиса (не входят в интерфейс/прокси):** `prepare`, `constructWhere`, `execute` — внутренние детали реализации.

---

## CacheService — структура

In-memory кэш **только на время одного запроса**. Структура:

```php
$_CACHE = [
    '_LOCALE'                    => ['id' => [0 => [...]]],
    '_CMSVC'                     => ['id' => ['objectName' => $cmsvcInstance]],
    'App\CMSVC\Foo\FooModel'     => ['id' => [$modelId => $model]],
];
// Доступ:
CACHE->getFromCache('App\CMSVC\Foo\FooModel', $id);
CACHE->setToCache('App\CMSVC\Foo\FooModel', $id, $model);
```

---

## CurrentUser — аутентификация и права

**`auth()`** — главный метод (вызывается в Kernel):
1. `Authorization: Bearer {jwt}` → проверяет JWT (exp + подпись)
2. Cookie `refreshToken` → обновляет JWT, выставляет новый cookie
3. `$_REQUEST['password']` → форм-логин

**Переключение профиля администратором:** если `$_REQUEST['adm_user']` или cookie `admUser` != 0, текущие данные админа сохраняются через `setAdminData()`, затем загружаются данные целевого пользователя.

**Ключевые методы:** `id()`, `sid()`, `isLogged()`, `isAdmin(strict=false)`, `isBanned()`, `checkAllRights($right_id)`, `getAllRights()`, `authLogout()`.

---

## LocaleHelper — структура файлов и API

### Маппинг первого аргумента → файл

| Первый ключ | Файл |
|------------|------|
| `'fraym'` | `src/Locale/{locale}.json` — строки фреймворка |
| `'global'` | `src/CMSVC/{locale}.json` — глобальные строки проекта (плоский JSON) |
| `'{module}'` | `src/CMSVC/{Module}/{locale}.json` — строки конкретного раздела |

Ключ автоматически конвертируется `camelCase → snake_case`. Данные кэшируются в `_LOCALE`.

### Структура JSON для модульных файлов (`src/CMSVC/{Module}/RU.json`)
```json
{
    "global": { "title": "...", "messages": { ... } },
    "fraym_model": {
        "object_name": "новость",
        "object_messages": ["Добавлена.", "Изменена.", "Удалена."],
        "elements": {
            "name": { "shownName": "Название", "helpText": "...", "values": [[...]] }
        }
    }
}
```

### Примеры вызовов
```php
LocaleHelper::getLocale(['fraym', 'fraymActions']);           // строки fraymAction
LocaleHelper::getLocale(['fraym', 'decline']);                // склонения
LocaleHelper::getLocale(['global']);                          // весь глобальный JSON
LocaleHelper::getLocale(['newsEdit', 'fraymModel', 'elements', 'name']); // метаданные поля
// getElementText() удобный враппер для последнего паттерна:
LocaleHelper::getElementText($entity, $element, LocalableFieldsEnum::shownName);
```

---

## Validation — server-side

Валидаторы навешиваются на элементы через атрибуты. Список:

| Валидатор | Назначение |
|-----------|-----------|
| `ObligatoryValidator` | Обязательное поле |
| `EmailValidator` | Формат email |
| `LoginValidator` | Уникальность логина |
| `MinMaxCharValidator` | Длина строки |
| `RepeatPasswordValidator` | Совпадение паролей |
| `FilesValidator` | Расширения и размер файлов |
| `TimestampValidator` | Формат даты |

Ошибки собираются в `$entity->validationErrors` → `fraymAction()` формирует `troubledElements`/`troubledStrings` → возвращаются в JSON-ответе → UI подсвечивает поля.

---

## RightsHelper — система прав

```php
RightsHelper::findByRights(
    type: 'admin',        // строка или массив типов
    obj_type_to: '{user}',
    obj_id_to: $userId,
    obj_type_from: '{user}',
    obj_id_from: null,    // null → CURRENT_USER->id()
    limit: 1,
);
// → массив ids из таблицы rights, или null
```

Встроенные типы: `banned`, `admin`. Остальные — бизнес-логика проекта.

---

## DataHelper — важные методы

| Метод | Назначение |
|-------|-----------|
| `virtualStructure(array)` | array → JSON для virtualField |
| `unmakeVirtual(json)` | Распаковка виртуального поля |
| `activityLog(fullLog)` | Логирование в Fiber (асинхронно) |
| `adminEcho(str)` | Вывод только для админа в test mode |
| `getRandomStringBin2hex(length)` | Криптографичный случайный токен |
| `base64UrlEncode(str)` | Base64 для JWT (без padding) |
| `inArrayAll(needles, haystack)` | Проверка — все элементы присутствуют |
| `inArrayAny(needles, haystack)` | Проверка — хотя бы один элемент |
| `checkNumeric(data)` | Конвертация строк в числа где возможно |
| `getActDefault(entity)` | ActEnum (list vs edit) по наличию ID |
| `getId()` | `ID[key(ID)]` — первый ID из глобального массива |

---

## Migration — механизм

```php
// src/BaseObject/BaseMigration.php
abstract class BaseMigration {
    use SqlTrait;                   // getSql() + executeSql()
    abstract public function up(): bool;
    abstract public function down(): bool;
    public function getFixture(): ?BaseFixture { ... }  // ищет FixtureXXX автоматически
}
```

`MIGRATE_DB` — отдельная константа PDO-соединения для миграций (отдельное от `DB`). Fixtures загружаются после миграции автоматически.

---

## CatalogEntity / CatalogItemEntity

Для иерархических структур (раздел → элементы раздела):

```php
class CatalogEntity extends BaseEntity {
    public CatalogItemEntity $catalogItemEntity;  // дочерняя сущность
}
class CatalogItemEntity extends BaseEntity {
    public CatalogEntity $catalogEntity;
    public string $tableFieldWithParentId;       // обычно 'parent_id'
    public string $tableFieldToDetectType;
}
```

`service->detectModelTemplateBasedOnData($data)` — определяет, каталог это или элемент каталога, на основе данных строки БД.

---

## TableEntity / MultiObjectsEntity

**TableEntity** — обычная таблица с переходом на карточку сущности.

**MultiObjectsEntity** — множество объектов на одной странице. `subType` из `MultiObjectsEntitySubTypeEnum`: Excel или Cards. Все поля выводятся в контексте `:list`.

---

## EnvService — особенности парсинга

```
VAR=true              → bool true
VAR=false             → bool false
VAR={"key":"val"}     → ассоциативный массив (JSON autodetect по первому символу)
VAR[0]=item1          → $_ENV['VAR'][0] = 'item1'  (массив через индексы)
# комментарий         → игнорируется
```

---

## ResponseHelper — дополнительные методы

```php
ResponseHelper::redirectConstruct($checkStandard, $deleteId); // вычисляет путь редиректа после fraymAction
ResponseHelper::success($message);   // сохранить в cookie → показать при следующем запросе
ResponseHelper::error($message);     // аналогично
ResponseHelper::info($message);      // аналогично
```

---

## Ключевые env-переменные

| Переменная         | Описание                                          |
|--------------------|---------------------------------------------------|
| `PROJECT_HASH_WORD`| Pepper для паролей, ключ HMAC JWT и CSRF-токенов  |
| `ABSOLUTE_PATH`    | URL сайта без слэша                               |
| `STARTING_KIND`    | KIND по умолчанию при обращении к `/`             |
| `ALLOWED_ORIGINS`  | CORS: пусто / `*` / `https://a.com,https://b.com` |
| `DATABASE_TYPE`    | `pgsql` или `mysql`                               |
| `TIMEZONE`         | PHP timezone                                      |
| `DESIGN_PATH`      | Субпапка дизайна (CSS/шаблоны проекта)            |
| `COOKIE_PATH`      | Domain для cookie (используется в CookieHelper)   |
