# Security

Fraym aims to be safe by default using plain PHP 8.4 — no security add-ons. This
document describes the threat model and the concrete mechanisms.

## Authentication

- **JWT** (HS256, 1-hour lifetime) carrying only `id`, `sid`, `exp`. Rights and
  quotas are loaded fresh from the database on every authenticated request, so a
  revoked right takes effect immediately rather than lingering for up to an hour.
- The JWT is stored in an **httpOnly, Secure, SameSite=Lax cookie** (`authToken`)
  for the browser SPA, so cross-site script injection cannot read it. External
  API clients may instead send `Authorization: Bearer <jwt>`.
- A **refresh token** (30-day, stored server-side) is kept in a separate httpOnly
  cookie. When the JWT expires, the SPA silently calls the refresh endpoint,
  which issues a fresh `authToken` cookie; the token value is never exposed to
  JavaScript.
- Passwords are hashed with **Argon2ID** plus a server-side pepper
  (`PROJECT_HASH_WORD`). Legacy hashes are transparently upgraded on next login.

## CSRF

Two complementary defenses:

- **Authenticated requests** carry a stateless CSRF token — an HMAC-SHA256 of
  `userId:sid:dailyNonce` under `PROJECT_HASH_WORD`, sent as `X-CSRF-Token`. It is
  validated on every cookie-authenticated write. Validation accepts today's and
  yesterday's nonce so the day boundary is seamless. CSRF is skipped **only** for
  requests authenticated via a Bearer token (real external API), which cannot be
  forged by a browser because the token lives in an httpOnly cookie.
- **Unauthenticated forms** (login, registration, password reset) use a
  **double-submit** token: the server sets a `csrf_pre_auth` cookie and embeds the
  same value in a hidden field; on submit the two must match
  (`AuthHelper::validatePreAuthCsrfToken()`). SameSite=Lax means a cross-site POST
  never carries the cookie.

## SQL injection

All dynamic SQL is parameterized. The query layer (`SQLDatabaseService`) binds
every value; the filter builder routes every user value through
`Filters\SqlCondition`, which emits auto-incrementing placeholders (`:f_0`, …) and
collects the values separately. No user-supplied value is ever concatenated into
SQL. Column/identifier names are developer-defined and quoted via the dialect.

## Output escaping (XSS)

Field values are escaped at render time by the Item renderers via
`DataHelper::escapeOutput()` (`htmlspecialchars` with `ENT_QUOTES`). Fields that
are HTML by design (`Wysiwyg`, or `Text`/`Textarea` with `saveHtml: true`) are
emitted verbatim. List-cell values and uploaded file names are escaped the same
way. Reference-data labels (`Select`/`Multiselect` options) may intentionally
contain HTML and are left as authored.

## Transport and network

- Cookies are `Secure` + `httpOnly` + `SameSite=Lax` by default, so the app must
  be served over HTTPS.
- The real client IP is taken from `REMOTE_ADDR` unless the request comes from a
  CIDR listed in `TRUSTED_PROXIES` (env), in which case `X-Forwarded-For` is
  honored. `X-Forwarded-For` is never trusted blindly.
- CORS is opt-in via `ALLOWED_ORIGINS`.

## Logging

Database exception messages never include raw query parameters. Sensitive values
(passwords, tokens, CSRF, secrets, hashes) are masked before logging via
`DatabaseQueryException::getMaskedParameters()`.

## Migration checklist for existing projects

Manually-copied files (`public/vendor/fraym/js/global.js` and its `.min`,
`public/fraym.php`, `src/index.php`, and the `Login` module) must be re-copied or
patched to pick up backend security fixes. In particular, custom login /
register / reset forms must include the `csrf_pre_auth` hidden field, or logins
will be rejected.
