# Architecture

Fraym is organized around one convention — **CMSVC** — and a handful of small,
explicit runtime services. This document maps the moving parts.

## Bootstrap chain

```
HTTP request
  → public/index.php
    → require fraym.php
      → vendor/autoload.php
      → set_exception_handler(...)      # catch-all → HTTP 500 (JSON for Fraym/API requests)
      → Kernel::init()                  # load env, define global constants, wire services
        catch DatabaseConnectionException → HTTP 503
    → include src/index.php             # routing + response dispatch
```

`.env` files load in order: `.env.fraym` → `.env` → `.env.dev|stage|prod`. Each
later file overrides the previous.

## Global constants and the proxy pattern

After `Kernel::init()` the following constants are available everywhere:

| Constant       | Type                 | Meaning                                  |
|----------------|----------------------|------------------------------------------|
| `DB`           | `DatabaseProxy`      | database service                         |
| `CACHE`        | `CacheProxy`         | per-request in-memory cache              |
| `CURRENT_USER` | `CurrentUserProxy`   | authenticated user                       |
| `ACTION`       | `?ActionEnum`        | requested action                         |
| `KIND`         | `string`             | current module                           |
| `REQUEST_TYPE` | `RequestTypeEnum`    | how the request arrived                  |

`DB`, `CACHE` and `CURRENT_USER` are **proxy objects**, not the services
themselves. Each call is delegated to `Container::make(id)` at call time. This
lets tests swap implementations (`Container::bind('db', $mock)`) and lets
persistent workers rebuild services (`Container::reset()`) without changing any
calling code.

```php
Container::bind('db', $mockDb);   // tests
DB->select(...);                  // → $mockDb->select(...)
```

## Request types

`RequestTypeEnum::getRequestType()` classifies each request:

| Type                    | Trigger                              |
|-------------------------|--------------------------------------|
| `FRAYM_REQUEST`         | `Fraym-Request: true` header (SPA)   |
| `FRAYM_API_REQUEST`     | `Authorization: Bearer …` header     |
| `HTMX_REQUEST`          | `HX-Request: true` header            |
| `NOT_A_DYNAMIC_REQUEST` | full page load (default)             |

API mode is keyed on the presence of a Bearer token, so a same-origin browser
script cannot spoof it to bypass CSRF (see [security](security.md)).

## CMSVC

A module is a folder under `src/CMSVC/{Kind}/` wiring five layers:
**C**ontroller → **M**odel → **S**ervice → **V**iew → **C**ontext. Layers are
linked with the PHP 8 attribute `#[CMSVC(...)]` / `#[Controller(...)]`.

```php
#[CMSVC(controller: NewsController::class, objectName: 'news')]
class NewsModel extends BaseModel {
    use IdTrait;
    use CreatedUpdatedAtTrait;

    #[Attribute\Text(obligatory: true, context: ['news:list', 'news:create', 'news:update'])]
    public Item\Text $title;
}
```

Objects use two-phase init (`construct()` then `CMSVC->init()`) so access rights
can be checked before models, services and views are loaded. Subsequent rows are
produced with `clone $templateModel`, which is cheaper than a fresh `construct()`.

### Contexts

Context tags of the form `objectName:suffix` (e.g. `news:list`) control field
visibility and render mode. A field is visible in a context if any of its
declared `context` strings match the active CMSVC context key (`LIST`, `VIEW`,
`CREATE`, `UPDATE`, `EMBEDDED`, …). Both the universal form (`:list`) and the
object-specific form (`news:list`) are accepted.

## The Element layer: Attribute → Item

Each field has two objects:

- **Attribute** (PHP 8 attribute) — static metadata: type, context, validation,
  display flags.
- **Item** (runtime instance) — holds the row value and knows how to `get()`,
  `set()`, `asHTML()`, `asArray()`.

The runtime class is derived from the attribute class by namespace substitution
(`Element\Attribute\Text` → `Element\Item\Text`). Built-in types: `Text`,
`Textarea`, `Number`, `Email`, `Password`, `Login`, `Hidden`, `Checkbox`,
`Select`, `Multiselect`, `File`, `Wysiwyg`, `Timestamp`, `Calendar`, `H1`, `Tab`.

## Entities

`BaseEntity` is the CRUD/render core. Its two largest responsibilities are split
into traits for readability:

- `FraymActionTrait::fraymAction()` — the create/change/delete gateway (rights →
  CSRF → validation → persistence).
- `EntityViewTrait::view()` — list and item rendering.

`Filters` (search/filter panel + WHERE construction) is likewise split into
`FiltersSqlTrait` and `FiltersHtmlTrait` behind a thin facade. All filter values
reach SQL only through parameterized `SqlCondition` placeholders.

## Database dialects

Dialect-specific SQL is isolated behind `DB->dialect->…` (Strategy pattern:
`MySQLDialect` / `PostgreSQLDialect`). Client code never branches on
`DATABASE_TYPE`; it asks the dialect for the right fragment
(`getInsertReturningClause()`, `orderByCustomValuesSql()`, …).

## CLI

`./vendor/bin/console` provides `install`, `make:cmsvc`, `make:migration`,
`database:migrate[:up|:down]`, and `database:drop` (dev/test only).
