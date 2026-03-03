# Fraym — Фронтенд-рантайм: архитектура и практики

---

## Обзор

Fraym SPA работает поверх собственного JS-рантайма. Нет React/Vue/Angular. Вместо этого:
- jQuery-подобный враппер `_()` с кэшированием
- Event delegation через MutationObserver
- History API для навигации без перезагрузки
- Lazy-загрузка JS/CSS модулей по запросу

**Слои JS:**

| Файл | Назначение |
|------|-----------|
| `public/vendor/fraym/js/global.js` | Fraym-рантайм: SPA, fetchData, FraymElement, компоненты |
| `public/js/global.js` | Проектный слой: `projectInit()`, `customHashHandler()` |
| `src/CMSVC/{Kind}/js.js` | JS конкретного раздела |
| `src/JsComponent/{name}.js` | Переиспользуемые компоненты (загружаются как `dataLoaded.libraries`) |

---

## Bootstrap-цепочка JS

```
<script src="/vendor/fraym/js/global.min.js">  → Fraym-рантайм, определяет _(), el(), fraymInit()
<script src="/js/global.min.js">               → Проектный JS, определяет projectInit()
<link class="localeUrl" ...>                   → Locale JSON

ready(fn)
  → fraymInit(true)      // первый запуск: глобальные event listeners + MutationObserver
    → projectInit(true)  // хук из public/js/global.js
      → loadJsCssForCMSVC()    // lazy-загрузка JS/CSS текущего KIND
      → initDynamicFields()    // инициализация условных полей
```

При SPA-навигации:
```
updateState(newHref)
  → fetchData(url, {json: true})
  → заменить div.maincontent_data
  → fraymInit(false)       // без withDocumentEvents — не переустанавливает listeners
    → projectInit(false)   // реинициализация компонентов для нового контента
```

---

## Core Global Variables

```js
jwtToken               // JWT-токен авторизации (обновляется автоматически)
LOCALE                 // загруженные локали (объект, ключи из JSON)
fraymElementsMap       // Map<DOMElement, FraymElement> — все активные элементы
activeListeners        // Map<FraymElement, handlers> — зарегистрированные listeners
dataLoaded             // { css, js, libraries, functions } — реестр загруженных модулей
window['csrfToken']    // CSRF-токен (инжектируется PHP только для залогиненных)
window['messages']     // очередь нотификаций [[type, text], ...] (инжектируется PHP)
```

---

## FraymElement API — `_()` враппер

```javascript
_(selector)           // получить/создать враппер (с кэшем в fraymElementsMap)
_(element, {noCache}) // без кэша (временные операции)
el(selector)          // querySelector — один элемент
elAll(selector)       // querySelectorAll — NodeList
elFromHTML(html)      // создать DOM из HTML-строки

// Цепочки:
_(this).closest('div.tr').find('input[name]').val('42').change();
_(this).addClass('active').show().insert('<span>text</span>', 'after');

// Жизненный цикл:
_(element).destroy()  // удалить из fraymElementsMap и activeListeners
```

**Ключевые методы FraymElement:**

| Метод | Описание |
|-------|---------|
| `.val(v)` | get/set value |
| `.text(t)` | get/set textContent |
| `.html(h)` | get/set innerHTML |
| `.attr(k,v)` | get/set attribute |
| `.css(p,v)` | get/set style |
| `.show/hide()` | display block/none |
| `.addClass/removeClass/hasClass/toggleClass()` | CSS-классы |
| `.closest(sel)` | bubbling up |
| `.find(sel, strictMode, includeStr, excludeStr)` | querySelector вниз |
| `.parent()` | parentElement |
| `.insert(el, where)` | before/after/start/end/replace |
| `.remove()` | removeChild |
| `.on(events, [selector], handler)` | добавить listener с event delegation |
| `.trigger(event)` | dispatchEvent |
| `.each(fn)` | итерация DOMElements |
| `.eq(n)` | n-й элемент из коллекции |
| `.index()` | позиция среди siblings |
| `.checked(v)` | get/set checked |
| `.enable/disable()` | disabled property |
| `.offset()` | getBoundingClientRect |
| `.animate(prop, to, dur)` | CSS-анимация (scrollLeft и т.д.) |
| `.isElementInViewport(cb)` | IntersectionObserver callback |
| `.asDomElement()` | первый DOM-элемент |
| `.asDomElements()` | массив DOM-элементов |

---

## Event Delegation

Fraym избегает прямого навешивания listeners на динамические элементы. Вместо этого — два механизма:

### 1. document-level delegation (withDocumentEvents)

```javascript
// Устанавливается один раз при withDocumentEvents=true (старт приложения)
_(document).on('click', '.careful', function (e) { ... });
_(document).on('submit', 'form', function (e) { ... });
_(document).on('click', '[action_request]:not(.careful)', function (e) { ... });
```

**Хранение:** в `activeListeners Map<FraymElement, {selector: {hash: {handler, listeners[]}}}>`

### 2. MutationObserver (globalFraymListenersObserver)

При добавлении новых DOM-узлов автоматически применяет все `activeListeners` к новым элементам:

```javascript
globalFraymListenersObserver.observe(document.body, { childList: true, subtree: true });
// → processInBatches(nodesToProcess, 0)  — батчами по 50 узлов через setTimeout(fn, 0)
```

**Итог:** listener нужно навешивать один раз через `_(document).on(...)` — он автоматически применится к любому будущему DOM-элементу.

---

## SPA-навигация: updateState()

```javascript
updateState(newHref)
```

**Логика:**
1. Парсит `newHref` через `parseUri()` — извлекает path и anchor
2. Если изменился только hash → переключает вкладку/модал без загрузки страницы
3. Если изменился path → `fetchData(url, {json: true})` с `Fraym-Request: true`
4. Сервер возвращает `{html, pageTitle, messages, executionTime}`
5. Заменяет `div.maincontent_data` новым HTML (+ выполняет `<script>` теги через `eval()`)
6. `history.pushState()` → меняет URL
7. Обновляет `<title>` и og-мета-теги
8. Вызывает `fraymInit(false, true)` для реинициализации

**Перехват ссылок:** `_(document).on('click', '[href^="/"]', ...)` — все внутренние ссылки перехватываются автоматически.

**Исключения:** ссылки с классом `.no_dynamic_content` или атрибутом `no_dynamic_content` — открываются без SPA.

**Индикатор загрузки:** `div.fullpage_cover` (skeleton-экран + loading spinner).

**Offline:** `window.addEventListener('offline')` → показывает `fullpage_cover.offline_shown` с сообщением.

---

## Action Request паттерн

Паттерн для любых действий без навигации (сохранение, удаление, фильтры и т.д.):

### Вызов из HTML:
```html
<a action_request="/news/action=delete" obj_id="42">Удалить</a>
```
Автоматически перехватывается: `_(document).on('click', '[action_request]:not(.careful)', ...)`.

### Вызов из JS:
```javascript
actionRequest({
    action: 'news_edit/save',
    obj_id: 42
}, targetElement);

// Для форм (dynamicForm):
actionRequest({action: 'news_edit/create', dynamicForm: true}, _(form));
```

### Регистрация коллбеков:
```javascript
// Fraym-сокращения:
_arSuccess('action_name', function (jsonData, params, target) { ... });
_arError('action_name', function (jsonData, params, target) { ... });

// Эквивалентно:
actionRequestCallbacks.success['action_name'] = function (jsonData, params, target) { ... };
actionRequestCallbacks.error['action_name'] = function (jsonData, params, target) { ... };
```

**Формат ответа сервера:**
```json
{"response": "success", "response_text": "Сохранено", "response_data": "...", "redirect": "/news/1/"}
{"response": "error", "response_text": "Ошибка валидации"}
```

`showMessageFromJsonData(jsonData)` — автоматически показывает noty-уведомление из ответа.

**Подавление ошибок:** `actionRequestSupressErrorForActions.push('action_name')` — не показывает noty при ошибке.

---

## Lazy-загрузка JS/CSS модулей

### Для CMSVC-разделов (`dataLoaded.js`):

```
GET /vendor/fraym/cmsvc/{kind}.js
→ public/vendor/fraym/cmsvc/js.php
→ src/CMSVC/{Kind}/js.min.js (или js.js)
→ оборачивает в: dataLoaded.js["{kind}"] = function(withDocumentEvents) { ... }
```

### Для переиспользуемых компонентов (`dataLoaded.libraries`):

```
GET /vendor/fraym/cmsvc/{name}.js?component=1
→ src/JsComponent/{name}.min.js (или .js)
→ оборачивает в: dataLoaded.libraries["{name}"] = function(withDocumentEvents) { ... }
```

Компоненты загружаются один раз — код выполняется при `withDocumentEvents=true`.

### CSS для разделов:
```html
<!-- Инжектируется в <head> сервером: -->
<link rel="stylesheet" href="/vendor/fraym/cmsvc/{kind}.css">
<!-- → public/vendor/fraym/cmsvc/css.php → src/CMSVC/{Kind}/css.min.css -->
```

### Проверка загрузки:
```javascript
ifDataLoaded('component_name', 'myFunction', element, function() {
    // вызывается когда библиотека загружена
});
```

---

## JWT и CSRF

**window.fetch Proxy** (автоматический):
- При каждом fetch к локальному origin инжектирует `Authorization: Bearer {jwtToken}`
- Если JWT нет — запускает refresh через `GET /login/action=refresh_token`
- Несколько одновременных запросов ждут одного refresh через Promise

**fetchData** (явный, для Fraym-запросов):
- Добавляет `Fraym-Request: true`
- Добавляет `Authorization: Bearer {jwtToken}`
- Добавляет `X-CSRF-Token: {window['csrfToken']}` (если залогинен)

**CSRF-токен:** инжектируется PHP в HTML при полной загрузке:
```html
<script>window["csrfToken"] = "<?= AuthHelper::generateCsrfToken() ?>";</script>
```

---

## Компоненты UI (Fraym)

### FilePond — загрузка файлов
```javascript
// Инициализация: <input class="inputfile" type="file" name="photo">
fraymFileUploadApply(element);
// Lazy-load: filepond.min.js + locale/{RU}.min.js
// XHR upload с CSRF-токеном
// Хранение: filepondObjs Map<name, FilePondInstance>
// Перед submit: fraymFileUploadInputsFix() → {filename:serverId}
```

### Quill — WYSIWYG
```javascript
// Инициализация: <div class="wysiwyg-editor" name="content">
fraymWysiwygApply(element);
// Lazy-load: quill.min.js
// Тулбар: bold, italic, strike, link, image, ordered/unordered list, code
// Хранение: wysiwygObjs Map<name, QuillInstance>
// Toggle режим кода: скрытый textarea
```

### Модальные окна (FraymModal)
```html
<a class="fraymmodal-window" href="/news/1/?modal=true" hash="news1">Открыть</a>
```
- `hash` → используется для `#anchor` в URL при открытии
- Контент загружается AJAX-ом по `href`
- История: `componentsUpdateState(hash)` при открытии/закрытии
- Закрытие: Escape, клик по overlay, `hrefAfterModalClose` для редиректа после

### Tabs (FraymTabs)
```html
<div class="fraymtabs">
  <ul><li><a href="#tab1" id="tab1">Вкладка 1</a></li></ul>
  <div id="tab1">...</div>
</div>
```
- Hash в URL при переключении (`#tab1`)
- Swipe-навигация на touch-устройствах
- Пустые панели → `disabled`
- `activateWithParents` event для вложенных табов

### Autocomplete (FraymAutocomplete)
```javascript
fraymAutocompleteApply(input, {
    source: '/users/?obj_type={user}&obj_id=0',
    makeEmptySearches: true,  // запрос при пустом поле
    select: function() { /* this.id — выбранный id */ },
    change: function(value) { this.options.minLength = 3; }
});
```
- Debounce 200ms
- Min 3 символа (переопределяемо)
- Поддержка conditional marker (например `@`)
- AJAX GET с параметром `term`

### Drag-Drop (FraymDragDrop)
```javascript
fraymDragDropApply(container, {
    onDrop: function(el, target) { ... },
    dragEnd: function(el) { ... }
});
```
- Sortable mode (сортировка внутри контейнера)
- Опциональный drag-handler элемент
- Drop targets

```html
<div class="dragdrop_container">
  <div class="dragdrop_item" obj_id="1">...</div>
  <div class="dragdrop_item" obj_id="2">...</div>
</div>
```

### Noty — уведомления
```javascript
showMessage({ text: 'Сохранено', type: 'success', timeout: 5000 });
showMessage({ text: 'Ошибка', type: 'error' });
// type: 'success' | 'error' | 'warning' | 'info'

fraymNotyPrompt(button, 'Вы уверены?', okCallback, cancelCallback);
notyDeleteButton(button, params);  // подтверждение удаления
createPseudoPrompt(html, title, buttons, onClose, onShow);  // кастомный диалог
```
- Очередь при старте: `window['messages'] = [[type, text], ...]` → `showMessages()`
- Layout: `bottomLeft`, theme: `fraym`
- `notyDialog` — глобальная ссылка на текущий диалог (для закрытия)

### SBI — SVG Background Inline
Элементы с классом `.sbi` автоматически инлайнятся как SVG:
```html
<span class="sbi sbi-edit"></span>
<!-- → public/vendor/fraym/design/edit.svg или public/design/sbi/edit.svg -->
```
MutationObserver `sbiObserver` отслеживает новые `.sbi` элементы и инлайнит их.

---

## Условные (динамические) поля

Поля могут показываться/скрываться в зависимости от значений других полей:

```javascript
initDynamicFields();        // читает dynamicFieldsList из DOM
toggleDynamicFields(el);    // вызывается при change
```

`dynamicFieldsList` формируется на стороне PHP (через RulingQuestion `show_if` JSON) и инжектируется в HTML.

`getDependencyItemsMapElementValues(elemName)` → возвращает текущие значения поля для вычисления видимости.

---

## Валидация форм

Всё client-side, без фреймворка:

```javascript
simpleValidate(field, condition, title)  // подсвечивает красным, показывает help
validateEmail(email)                      // regex валидация
validateTextMaxLength(field, maxlimit)    // счётчик символов
checkNumeric(field)                       // только числа
checkHttpUrl(string)                      // проверка URL
removeInvalidChars(field)                 // убирает emoji
```

Ошибки подсвечиваются через CSS-класс `error` на `.field_wrapper`, текст ошибки — в `.field_help`.

---

## Deep links и hash-routing

### customHashHandler(parsedHref)
Определяется в `public/js/global.js`. Вызывается при загрузке страницы и SPA-переходах. Проект реализует свою логику обработки hash-якорей:

```javascript
function customHashHandler(newHrefParsed) {
    const hash = newHrefParsed.anchor + '';

    if (/myAnchorPattern/.test(hash)) {
        scrollWindow(_(anchor).offset().top);
    }
    // Можно также кликнуть по fraymmodal-window[hash="..."]
}
```

### Hash в компонентах
- Tabs: `href="#tab_id"` → обновляет URL и переключает таб
- Modals: атрибут `hash="name"` → при открытии добавляет `#name` в URL

---

## SPA-область страницы (div.maincontent_data)

Fraym при SPA-навигации заменяет только содержимое `div.maincontent_data`. Остальная структура страницы остаётся нетронутой:

```html
<div class="maincontent">
  <div class="maincontent_wrapper">
    <div class="maincontent_data">
      <!-- Только этот блок заменяется при SPA-переходе -->
    </div>
  </div>
</div>

<!-- Оверлей загрузки / offline -->
<div class="fullpage_cover">
  <div id="circleG">...</div>   <!-- spinner -->
  <div id="skeletons">...</div> <!-- skeleton-экраны -->
  <div id="offlineMessage">...</div>
</div>
```

Всё, что находится вне `div.maincontent_data` (шапка, меню, футер) — постоянная часть страницы, реинициализируется только при `fraymInit(true)` (первый запуск).

---

## CSS-архитектура

**Нет CSS-фреймворка.** BEM-подобная структура.

### CSS custom properties (переменные)
Проект определяет переменные на уровне `body` или корневого контейнера:
```css
body {
    --font-family: "Roboto", Arial, sans-serif;
    /* цвета, тени, отступы... */
}
```

### Тёмная тема
- CSS: `@media (prefers-color-scheme: dark)` переопределяет переменные
- JS: `switchthemeColor(dark)` меняет `<meta name="theme-color">`
- Скроллбар кастомизируется через webkit-scrollbar

### Файловая структура CSS
```
public/vendor/fraym/css/global.css    # Фреймворк CSS (базовые стили)
public/css/global.css                  # Проектный CSS
public/css/global.min.css              # Минифицированный
public/css/components/                 # Компонентные CSS-файлы

src/CMSVC/{Kind}/
├── css.css                # Стили раздела
└── css.min.css            # Минифицированный
```

### Минификация
В продакшне используются `.min.js` и `.min.css`. `cmsvc/js.php` и `cmsvc/css.php` выбирают minified автоматически.

---

## Локали

**Загрузка:** PHP рендерит `<link class="localeUrl" ...>` теги, Fraym JS загружает все JSON-файлы и объединяет их в `LOCALE`:
```html
<link href="/vendor/fraym/locale/RU/locale.json" class="localeUrl" data-locale="RU">
<link href="/{path-to-project-locale}/RU/locale.json" class="localeUrl" data-locale="RU">
```

**Переключение:** GET `/locale=RU` (или EN/ES) → сохраняется в cookie `locale`.

**Файловая структура:**
```
public/vendor/fraym/locale/{RU|EN|ES}/locale.json  # строки фреймворка
src/CMSVC/RU.json / EN.json / ES.json               # глобальные строки проекта
src/CMSVC/{Kind}/RU.json / EN.json / ES.json        # строки раздела
```

**В JS:**
```javascript
LOCALE.save           // "Сохранить"
LOCALE.areYouSure     // "Вы уверены?"
LOCALE['module_key']  // строки конкретного модуля
```

---

## Паттерны в CMSVC JS-модулях

Каждый `src/CMSVC/{Kind}/js.js` выполняется внутри `dataLoaded.js["{kind}"] = function(withDocumentEvents) { ... }`.

Стандартная структура:

```javascript
// Всегда выполняется (при каждой загрузке раздела):
const myInput = el('input[name="field"]');

if (myInput) {
    fraymAutocompleteApply(myInput, { ... });
}

// Только при первом запуске (document event listeners):
if (withDocumentEvents) {
    _(document).on('change', 'select[name="status"]', function () {
        // ...
    });

    _arSuccess('my_action', function(jsonData, params, target) {
        showMessageFromJsonData(jsonData);
        target.closest('div.tr').remove();
    });
}
```

**Важно:** `if (withDocumentEvents)` обязателен для event listeners — иначе они дублируются при каждом SPA-переходе внутри раздела.

### Типичные паттерны в модулях

**Дебаунс для поиска:**
```javascript
_(document).on('keyup', 'input[name="search"]', function() {
    debounce('search', function() {
        actionRequest({ action: 'news/search', query: _(this).val() });
    }, 300);
});
```

**Обновление части страницы через _arSuccess:**
```javascript
_arSuccess('get_news_list', function(jsonData, params, target) {
    target.closest('div.table_wrapper').html(jsonData['response_data']);
    // Если в новом контенте есть FilePond/Quill — инициализировать точечно:
    // fraymFileUploadApply(el(...))
    // Event delegation через _(document).on() продолжает работать автоматически
});
```

**Инлайн-редактирование:**
```javascript
_(document).on('click', 'a.inline_edit', function() {
    _(this).closest('div.view_field').hide();
    _(this).closest('div.view_field').next('div.edit_field').show();
});
```

**Условное отображение по select:**
```javascript
_(document).on('change', 'select[name="type"]', function() {
    showHideByValue('div.conditional_block', _(this).val());
});
```

---

## Показ/скрытие контента (паттерны)

```html
<!-- Кнопка "показать ещё" -->
<a class="show_hidden">Показать ещё</a>
<div class="hidden"><div>...</div><div>...</div></div>

<!-- Overflow-ограничение с раскрытием -->
<div class="overflown_content">...</div>
<a class="show_hidden">Показать всё</a>

<!-- Таблица со скрытыми строками -->
<a class="show_hidden_table">Показать все N записей</a>
```

Все обрабатываются event delegation в `projectInit`.

---

## Автоматический клик по viewport (Lazy-auto-load)

Fraym поддерживает паттерн автоматического клика на элемент при попадании в viewport:

```javascript
// В projectInit():
autoClickLoad('a.load_articles', null, 'some_component');
```

**Механизм:** `isElementInViewport(callback)` → IntersectionObserver → `self.trigger('click')`.

**В HTML:**
```html
<a class="load_articles" obj_type="news" obj_id="0">загрузить список</a>
```

---

## Утилиты (часто используемые в модулях)

```javascript
debounce('id', fn, delay)            // отложить выполнение, отменяя предыдущий
delay(ms)                            // Promise-timeout
absolutePath()                       // текущий origin сайта
autoLayoutKeyboard(str)              // конвертация раскладки RU↔EN для поиска
getLocale()                          // текущий код локали ('RU', 'EN', 'ES')
isNumeric(v) / isInt(v)              // проверки типа
hashCode(str)                        // числовой хеш строки
getOrCreateDeviceId()                // UUID из localStorage
mobilecheck()                        // touch-устройство?
parseUri(str)                        // {protocol, host, path, query, anchor}
getExtension(filename)               // расширение файла
clearBraces(str)                     // '{user}' → 'user'
appendLoader(el) / removeLoader(el)  // спиннер на кнопку
scrollWindow(height)                 // плавный скролл
submenuToggle(trigger, menu)         // toggle dropdown
showHideByValue(selector, value)     // показать элемент с data-value=value
fixateIndexerButtons()               // sticky кнопки фильтров
preload(arrayOfImageUrls)            // preload изображений
defaultFor(value, defaultValue)      // безопасное чтение с fallback
```

---

## Антипаттерны / подводные камни

1. **`fraymInit(false)` при частичной замене HTML** — `fraymInit(false)` предназначен для полной реинициализации страницы после SPA-навигации. При точечной вставке HTML в коллбеке `_arSuccess` его вызывать не нужно — event delegation через `_(document).on(...)` продолжает работать автоматически. Если в новом контенте есть компоненты (FilePond, Quill), инициализировать их напрямую: `fraymFileUploadApply(el)`, `fraymWysiwygApply(el)`.

2. **`if (withDocumentEvents)` в модуле** — `_(document).on(...)` защищён от дублирования: хэш от `listeners + selector + handler.toString()` предотвращает повторную регистрацию одинакового обработчика. Обёртка `if (withDocumentEvents)` остаётся хорошей практикой для явности и избегания лишних вызовов.

3. **`_(element, {noCache: true})`** — нужен при временных операциях (в цикле, в коллбеках), чтобы не засорять `fraymElementsMap`.

4. **`fraymFileUploadInputsFix()` перед submit** — если используется FilePond, нужно вызывать для фиксации имён файлов в hidden-полях.

5. **Не использовать `document.querySelector` напрямую** — только `el()`, `elAll()`, `_()`, чтобы не обходить систему кэширования.

6. **`defaultFor(window['var'], default)`** — при SPA-навигации window-переменные из предыдущей страницы могут сохраняться; `defaultFor` не перезаписывает уже установленное значение.

7. **CSS модулей загружается динамически** — `loadJsCssForCMSVC()` вызывает `cssLoad()`, которая добавляет `<link>` в `<head>` при каждом SPA-переходе (с проверкой `dataLoaded.css[name]` — дважды не добавляется). CSS модуля доступен сразу после перехода в раздел.
