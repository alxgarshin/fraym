<?php

declare(strict_types=1);

namespace App\Helper;

use Fraym\Interface\Helper;

abstract class DesignHelper implements Helper
{
    /** Inline css property background-image */
    public static function getCssBackgroundImage(string $path): string
    {
        return "background-image: url('" . $path . "')";
    }

    /** Add a header to input forms */
    public static function insertHeader(string $content, ?string $header): string
    {
        if (preg_match('#<div class="maincontent_data([^"]*)"><h1 class="form_header">.*?</h1>#', $content)) {
            return preg_replace(
                '#<div class="maincontent_data([^"]*)"><h1 class="form_header">.*?</h1>#',
                '<div class="maincontent_data$1"><h1 class="form_header">' . self::changePageHeaderTextToLink($header, '/' . KIND . '/') . '</h1>',
                $content,
            );
        }

        return preg_replace(
            '#<div class="maincontent_data([^"]*)">#',
            '<div class="maincontent_data$1"><h1 class="form_header">' . self::changePageHeaderTextToLink($header, '/' . KIND . '/') . '</h1>',
            $content,
        );
    }

    /** Add scripts inside the main response div */
    public static function insertScripts(string $content, string $scripts): string
    {
        return mb_substr($content, 0, -6) . $scripts . '</div>';
    }

    /** Wrap the page header in a link */
    public static function changePageHeaderTextToLink(?string $text, ?string $href = null): string
    {
        return (!is_null($href) ? '<a href="' . $href . '">' : '') . (string) $text . (!is_null($href) ? '</a>' : '');
    }
}
