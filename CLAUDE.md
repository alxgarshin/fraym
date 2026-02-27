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
│   ├── Enum/                     # ActionEnum, RequestTypeEnum, DbTypeEnum, ...
│   ├── Exception/                # DatabaseException, DatabaseConnectionException, DatabaseQueryException
│   ├── Helper/                   # AuthHelper, ResponseHelper, CookieHelper, DataHelper, ...
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

## Фронтенд-рантайм: skeleton/public/vendor/fraym/js/global.js

### Ключевые глобальные переменные
```js
jwtToken               // JWT-токен авторизации (обновляется автоматически)
LOCALE                 // загруженные локали (объект, ключи из JSON)
fraymElementsMap       // Map<DOMElement, FraymElement> — все активные элементы
activeListeners        // Map<FraymElement, handlers>
dataLoaded             // { css, js, libraries, functions } — реестр загрузки
window['csrfToken']    // CSRF-токен (инжектируется PHP только для залогиненных)
window['messages']     // очередь нотификаций (инжектируется PHP)
```

### fetchData(url, options, data) — базовая HTTP-функция
Все запросы фреймворка идут через неё. Автоматически добавляет:
- `Fraym-Request: true`
- `Authorization: Bearer {jwtToken}` (если есть)
- `X-CSRF-Token: {csrfToken}` (если `window['csrfToken']` задан)

`window.fetch` обёрнут в Proxy: автоматически обновляет JWT через refresh endpoint при 401.

### actionRequest(params, target) — обработка форм
Используется для submit действий (не навигационных). Строит URL из `params.action`,
вызывает `fetchData`, dispatch результата в `actionRequestCallbacks.success/error[action]`.

### updateState(href) — SPA-навигация
Перехватывает клики по локальным ссылкам. Загружает HTML через `fetchData` с заголовком
`Fraym-Request: true`, заменяет `div.maincontent_data`, вызывает `fraymInit(false)`.

### fraymInit(withDocumentEvents) — реинициализация элементов
Запускается после каждой смены контента. Инициализирует все поля, listeners, компоненты.
`withDocumentEvents: true` только при первом запуске (устанавливает MutationObserver).

### Lazy-загрузка JS/CSS по модулям
```
/vendor/fraym/cmsvc/{kind}.js   → php: cmsvc/js.php  → src/CMSVC/{Kind}/js.js
/vendor/fraym/cmsvc/{kind}.css  → php: cmsvc/css.php → src/CMSVC/{Kind}/css.css
```
JS-модуль оборачивается в `dataLoaded.js[kind] = function(withDocumentEvents) { ... }`.
JS-компоненты (переиспользуемые) — `dataLoaded.libraries[component]`.

### Загрузка файлов
**FilePond** (`js/filepond/`): drag-and-drop загрузчик, `filepondObjs` Map для управления.
`XMLHttpRequest` для аплоада. CSRF-токен передаётся через `setRequestHeader`.

### Встроенные UI-компоненты
- **Modal** (`js/modal/`) — `fraymmodal-window`, history-aware
- **Tabs** (`js/tabs/`) — переключение вкладок со swipe на touch-устройствах
- **Noty** (`js/noty/`) — нотификации (success/error/info)
- **Quill** (`js/quill/`) — WYSIWYG редактор
- **Autocomplete** (`js/autocomplete/`) — автодополнение для полей
- **Audioplayer** (`js/audioplayer/`) — аудиоплеер
- **Styler** (`js/styler/`) — визуальный редактор стилей
- **Dragdrop** (`js/dragdrop/`) — drag-and-drop сортировка
- **SBI** — SVG Background Inline: `.sbi`-элементы автоматически инлайнятся из `/vendor/fraym/design/*.svg`

### Локали фронтенда
```
skeleton/public/vendor/fraym/locale/{RU|EN|ES}/locale.json   # фреймворк
skeleton/src/CMSVC/{Kind}/{RU|EN|ES}.json                    # модуль
skeleton/src/CMSVC/{RU|EN|ES}.json                           # глобальные
```
Загружаются через `<a class="localeUrl" href="...">`, объединяются в `LOCALE`.

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
