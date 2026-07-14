# Fraym

Fraym is a boxed PHP framework for building ERP-style web applications with a
minimum of boilerplate. It ships both a PHP backend (the `fraym/fraym` package)
and a self-contained JS/CSS frontend runtime — no build step, no Node, no SPA
framework required.

## Philosophy

Fraym deliberately avoids heavy abstractions. There is **no ORM**, **no template
engine**, **no DI container framework**, and **no JS bundler**. Instead it gives
you a small, explicit core you can read end to end:

- Data access is a thin, fully parameterized query layer over PDO.
- HTML is produced by plain PHP string building plus an output-escaping helper.
- The frontend is a ~5k-line vanilla-JS runtime (`_()` wrapper, SPA navigation,
  lazy module loading) served as static assets.
- A 40-line service locator (`Container`) plus proxy constants (`DB`, `CACHE`,
  `CURRENT_USER`) make the core testable and worker-friendly without a framework.

If you want convention-driven CRUD modules, first-class MySQL/PostgreSQL support,
and a frontend that works without a toolchain, Fraym fits. If you want a
Symfony/Laravel-style ecosystem with an ORM and Twig, it does not.

## Requirements

- **PHP 8.4**
- **PostgreSQL 13+** or **MySQL 5.7+**

## Installation

```shell
composer require alxgarshin/fraym
./vendor/bin/console install
```

Configure the database connection in `.env.dev`, then run the initial migration:

```shell
./vendor/bin/console database:migrate --env=dev
```

Your project is ready to go.

## Documentation

- [Getting started](docs/getting-started.md) — a CRUD module in five minutes.
- [Architecture](docs/architecture.md) — CMSVC, bootstrap, container, entities, elements.
- [Security](docs/security.md) — auth model, CSRF, SQL safety, output escaping.
- [Frontend contract](docs/frontend-contract.md) — the PHP ↔ JS runtime boundary.
- [Changelog](CHANGELOG.md)

## License

MIT — see [LICENSE](LICENSE).
