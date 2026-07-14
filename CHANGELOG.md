# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

A security-and-architecture hardening pass across the PHP core and the JS runtime.

> **Upgrading:** the JS/CSS runtime, `public/fraym.php`, `src/index.php` and the
> `Login` module are copied into projects by hand — re-copy them (including
> `global.min.js`) to receive these changes. See [security](docs/security.md) and
> the notes below.

### Security

- **JWT moved to an httpOnly cookie** (`authToken`) for the browser; the token is
  no longer held in JavaScript. External API clients keep using
  `Authorization: Bearer`. **Breaking:** the refresh endpoint no longer returns
  the token in its body — it sets the cookie.
- **JWT payload slimmed** to `id`/`sid`/`exp`; rights and quotas are loaded from
  the database per request, so right revocation is immediate.
- **CSRF hardened:** API mode (and the CSRF skip) is now keyed on a Bearer token
  instead of a spoofable header, so same-origin scripts can no longer bypass it.
  Login/registration/reset forms now require a `csrf_pre_auth` double-submit
  token. **Breaking:** custom auth forms must include the hidden field.
- **SQL injection closed in Filters:** every user value is parameterized through
  `SqlCondition`; legacy multiselect dash-format search is gated behind an
  explicit `legacySearch` attribute.
- **JWT/CSRF verification** uses constant-time comparison, strict base64url, and
  explicit `alg`/segment checks.
- **Output escaping** added for list-cell values and uploaded file names.
- **Log hygiene:** query parameters are masked in database exceptions; inline JS
  page messages are JSON-encoded with hardening flags.
- **Real client IP** honors `X-Forwarded-For` only from `TRUSTED_PROXIES`.
- Hardcoded default DB password removed in the CLI; `KIND`/`CMSVC`/`OBJ_TYPE`
  validated in the kernel.

### Frontend reliability

- SPA navigation validates the server response before mutating the DOM, guards
  against navigation races, and redirects to login when a token refresh fails.
- Component loaders no longer poll forever on a 404; modal/tabs/autocomplete/
  drag-drop are guarded against double initialization.

### Architecture

- `BaseEntity` split into `FraymActionTrait` and `EntityViewTrait`; `Filters`
  split into `FiltersSqlTrait` and `FiltersHtmlTrait`; the `Multiselect` renderer
  split into editable/readonly methods — no public API changes.
- Database dialect differences fully isolated behind `DB->dialect`.
- `RightsRestrict` gains a declarative `criteria` parameter (no fragile alias
  regex).
- Magic strings replaced with enums/consts (`PasswordHashVersion`,
  `BuiltInRights`, `DOUBLE_SAVE_GRACE_SECONDS`).
- Activity logging offloaded via `register_shutdown_function` +
  `fastcgi_finish_request` (response is sent before the log write).

### Testing

- PHPUnit added with Unit and Security suites (JWT/CSRF, SQL-injection safety,
  multiselect parsing) and a lightweight CI workflow (cs-fixer, PHPStan, PHPUnit).
