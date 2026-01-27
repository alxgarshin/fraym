# FRAYM
PHP framework that brings everything you need to develop a not-so-small web-project including best practices for frontend solutions.

#### Requirements

- **PHP 8.4**
- **PostgreSQL 13** (or MySQL 5.7)

## Installation

Using composer:

```shell script
composer require alxgarshin/fraym
```

After that just use:

```shell script
./vendor/bin/console install
```

Setup DB connection in `.env.dev.`

And do a basic migration:

```shell script
docker compose exec app ./vendor/bin/console database:migrate --env=dev
```

Your project is ready to go!