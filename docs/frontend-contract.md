# Frontend contract

The Fraym frontend is a vanilla-JS runtime shipped as static assets
(`public/vendor/fraym/js/global.js` + its minified twin). It talks to the PHP
core over a small, stable contract. This document is that contract.

> **Versioning.** The JS runtime and the PHP core are released together. Because
> the JS/CSS assets are copied into each project by hand, they can drift from the
> backend after an upgrade. Always re-copy **both** `global.js` **and**
> `global.min.js` (the minified file is the one actually served) when you update
> the `fraym/fraym` package, and keep them in the same release as the backend.

## SPA navigation

Internal links (`href^="/"`) are intercepted and resolved without a full reload:

1. `updateState(href)` fetches the target with `Fraym-Request: true`.
2. The server returns `{ html, pageTitle, messages, executionTime }` or
   `{ redirect }`.
3. The response is validated **before** any DOM mutation — an invalid response
   throws and the current page is left intact.
4. `div.maincontent_data` is replaced; inline `<script>` tags run inside an IIFE
   (top-level `const`/`let` are **not** global); `<script src>` tags are loaded.
5. `history.pushState()` updates the URL; `fraymInit(false)` re-initializes
   components.

Links with class `.no_dynamic_content` (or the attribute) bypass the SPA.

## Action requests

Non-navigational actions (save, delete, filter) use `actionRequest`:

```js
actionRequest({ action: '/news/action=delete', obj_id: 42 }, targetElement);

// register callbacks:
_arSuccess('delete', (jsonData, params, target) => { /* … */ });
_arError('delete',   (jsonData, params, target) => { /* … */ });
```

Server response shape:

```json
{ "response": "success", "response_text": "Saved", "response_data": "…", "redirect": "/news/1/" }
{ "response": "error",   "response_text": "Validation failed" }
```

`showMessageFromJsonData()` renders the notification automatically.

## `fetchData` and authentication

`fetchData(url, options, data)` adds `Fraym-Request: true` and, for logged-in
users, `X-CSRF-Token`. Authentication itself is **cookie-based**: the browser
sends the httpOnly `authToken` cookie automatically — JavaScript neither reads
nor sets the JWT.

When a request returns **401**, the runtime calls the refresh endpoint once
(which sets a fresh `authToken` cookie), retries the original request, and — if it
still fails — redirects to `/login/`. A single-flight guard prevents parallel
refreshes.

External API clients that are not the browser SPA authenticate with
`Authorization: Bearer <jwt>` instead; those requests are treated as API requests
and skip CSRF.

## Lazy module loading

Per-module JS/CSS is fetched on demand:

- `GET /vendor/fraym/cmsvc/{kind}.js` → wrapped as `dataLoaded.js["{kind}"]`.
- `GET /vendor/fraym/cmsvc/{name}.js?component=1` → `dataLoaded.libraries["{name}"]`.

A module's `js.js` runs inside `dataLoaded.js["{kind}"] = function(withDocumentEvents){…}`.
Guard document-level listeners with `if (withDocumentEvents)` so they are not
re-registered on in-section SPA transitions.

## Built-in components

Modal, Tabs, Noty (notifications), Quill (WYSIWYG), FilePond (uploads),
Autocomplete, Drag-drop, Audio player, file-input styler, and inline SVG (`.sbi`).
Persistent components outside `div.maincontent_data` are guarded against
re-initialization across SPA transitions.
