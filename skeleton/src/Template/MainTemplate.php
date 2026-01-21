<?php

declare(strict_types=1);

namespace App\Template;

use Fraym\Helper\{CookieHelper, LocaleHelper};
use Fraym\Interface\Template;

final class MainTemplate implements Template
{
    public static function asHTML(): string
    {
        $LOCALE = LocaleHelper::getLocale(['global']);

        $LOCALE_NAME = CookieHelper::getCookie('locale');

        $RESPONSE_TEMPLATE = '<!doctype html>
<html prefix="og: https://ogp.me/ns#" lang="ru">
<head>

<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="Description" Content="' . $LOCALE['meta_description'] . '">
<meta name="Keywords" Content="' . $LOCALE['meta_keywords'] . '">

<meta property="og:title" content="<!--pagetitle-->" />
<meta property="og:description" content="' . $LOCALE['meta_description'] . '" />
<meta property="og:type" content="website" />
<meta property="og:url" content="https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . '" />
<meta property="og:site" content="' . $LOCALE['sitename'] . '" />

<meta name="twitter:card" content="summary_large_image" />
<meta property="twitter:site" content="' . $LOCALE['sitename'] . '" />
<meta property="twitter:title" content="<!--pagetitle-->" />
<meta property="twitter:url" content="https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . '" />

' . (($_ENV['CANONICAL_URL'] ?? false) ? '<link rel="canonical" href="' . $_ENV['CANONICAL_URL'] . '" />
' : '') . '
<title><!--pagetitle--></title>

<link href="/vendor/fraym/locale/' . $LOCALE_NAME . '/locale.json" class="localeUrl" data-locale="' . $LOCALE_NAME . '" crossOrigin>
<link href="/locale/' . $LOCALE_NAME . '/locale.json" class="localeUrl" data-locale="' . $LOCALE_NAME . '" crossOrigin>
<script type="text/javascript" src="/vendor/fraym/js/global.min.js"></script>
<script type="text/javascript" src="/js/global.min.js"></script>

<link rel="stylesheet" type="text/css" href="/vendor/fraym/css/global.min.css">
<link rel="stylesheet" type="text/css" href="/css/global.min.css">
<link rel="stylesheet" type="text/css" href="/vendor/fraym/cmsvc/' . KIND . '.css">

</head>

<body class="project">
<!--maincontent-->
<!--messages-->
</body>
</html>';

        return $RESPONSE_TEMPLATE;
    }
}
