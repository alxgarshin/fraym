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
| `DB`            | `SQLDatabaseService`   | `Container::make('db')`                   |
| `CACHE`         | `CacheService`         | `Container::make('cache')`                |
| `CURRENT_USER`  | `CurrentUser`          | `Container::make('current_user')`         |
| `ACTION`        | `?ActionEnum`          | `$_REQUEST['action']`                     |
| `KIND`          | `string`               | `$_REQUEST['kind']` или `STARTING_KIND`   |
| `CMSVC`         | `string`               | `$_REQUEST['cmsvc']` или `KIND`           |
| `ID`            | `array\|null`          | `$_REQUEST['id']` (нормализован в массив) |
| `REQUEST_TYPE`  | `RequestTypeEnum`      | Определяется по заголовкам запроса        |
| `ABSOLUTE_PATH` | `string`               | `$_ENV['ABSOLUTE_PATH']`                  |
| `GLOBALTIMER`   | `GlobalTimerService`   | —                                         |

### Container (src/Container.php)
```php
Container::bind('db', $instance);   // регистрация (до define)
Container::make('db');              // получение
Container::reset();                 // сброс (для persistent workers / тестов)
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
