# Getting started

This walks through a working CRUD module in about five minutes. It assumes you
have installed Fraym (`composer require alxgarshin/fraym` + `./vendor/bin/console
install`) and configured `.env.dev`.

## 1. Generate a module

```shell
./vendor/bin/console make:cmsvc --cmsvc=News
```

This scaffolds `src/CMSVC/News/` with a Controller, Model, Service, View, JS, CSS
and locale files. The command never overwrites existing files, so it is safe to
re-run.

## 2. Declare fields on the model

Edit `src/CMSVC/News/NewsModel.php`. Fields are public `Item\*` properties
annotated with a matching `#[Attribute\*]`:

```php
#[CMSVC(controller: NewsController::class, objectName: 'news')]
class NewsModel extends BaseModel
{
    use IdTrait;
    use CreatedUpdatedAtTrait;
    use CreatorIdTrait;

    #[Attribute\Text(obligatory: true, context: ['news:list', 'news:create', 'news:update'], minChar: 3, maxChar: 255)]
    public Item\Text $title;

    #[Attribute\Wysiwyg(context: ['news:view', 'news:create', 'news:update'])]
    public Item\Wysiwyg $body;

    #[Attribute\Select(values: 'getStatuses', context: ['news:list', 'news:create', 'news:update'])]
    public Item\Select $status;

    public function getStatuses(): array
    {
        return [['draft', 'Draft'], ['published', 'Published']];
    }
}
```

The `context` array decides where each field appears: the `title` shows in the
list, create and update screens; `body` is hidden from the list; `status` options
come from a model method named in `values`.

## 3. Create the table

```shell
./vendor/bin/console make:migration
```

Fill in the generated `src/Migrations/Sql/Sql{timestamp}.sql` (PostgreSQL) and,
if the DDL differs, `Sql{timestamp}.mysql.sql` (MySQL). A minimal table needs
`id`, your columns, and the trait columns (`created_at`, `updated_at`,
`creator_id`). Then apply it:

```shell
./vendor/bin/console database:migrate --env=dev
```

In dev/test the migration also creates the database and user if missing and runs
any matching fixture automatically.

## 4. Use it

Navigate to `/news/`. Fraym renders the list, the create/edit forms, validation,
and delete — all from the field declarations. Saving, updating and deleting go
through the built-in `create` / `change` / `delete` actions; you only write
custom controller methods when you need behavior beyond CRUD.

## Where to go next

- Add lifecycle hooks (`#[PreCreate]`, `#[PostChange]`, …) in the Service.
- Add per-field validation via attribute flags (`obligatory`, `minChar`, …).
- Read the [architecture](architecture.md) and [security](security.md) docs.
