<?php

/*
 * This file is part of the Fraym package.
 *
 * (c) Alex Garshin <alxgarshin@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Fraym\Helper;

use Fraym\Interface\Helper;

abstract class CookieHelper implements Helper
{
    /** Массовое создание cookie
     * @param array<string|int, string|int|array> $cookies
     */
    public static function batchSetCookie(array $cookies, ?int $time = null, ?string $samesite = null): void
    {
        foreach ($cookies as $cookieKey => $cookie) {
            if (self::getCookie($cookieKey) !== (is_array($cookie) ? DataHelper::jsonFixedEncode($cookie) : $cookie)) {
                if (self::getCookie($cookieKey) !== null) {
                    self::deleteCookieFromHeaders($cookieKey);
                }

                setcookie($cookieKey, is_array($cookie) ? DataHelper::jsonFixedEncode($cookie) : (string) $cookie, CookieHelper::getOptions($time, $samesite));
            }
        }
    }

    /** Получение cookie (в том числе выставленного только что) */
    public static function getCookie(string $cookieName, bool $isArray = false): string|array|null
    {
        $cookies = self::getCookiesFromHeaders();

        if (!is_null($cookies[$cookieName] ?? null)) {
            return $isArray ? DataHelper::jsonFixedDecode($cookies[$cookieName]) : $cookies[$cookieName];
        }

        if (!is_null($_COOKIE[$cookieName] ?? null)) {
            return $isArray ? DataHelper::jsonFixedDecode($_COOKIE[$cookieName]) : $_COOKIE[$cookieName];
        }

        return $isArray ? [] : null;
    }

    /** Массовое удаление cookie
     * @param string[] $cookiesNames
     */
    public static function batchDeleteCookie(array $cookiesNames, ?string $samesite = null): void
    {
        foreach ($cookiesNames as $cookieKey) {
            self::deleteCookieFromHeaders($cookieKey);

            setcookie($cookieKey, '', CookieHelper::getOptions(time() - 20, $samesite));
        }
    }

    /** Удаление всех cookie сайта */
    public static function deleteAllCookies(?string $samesite = null): void
    {
        if (isset($_SERVER['HTTP_COOKIE'])) {
            $cookies = explode(';', $_SERVER['HTTP_COOKIE']);

            foreach ($cookies as $cookie) {
                $parts = explode('=', $cookie);
                $name = trim($parts[0]);
                setcookie($name, '', CookieHelper::getOptions(time() - 20, $samesite));
            }
        }
    }

    /** Получение стандартного набора свойств для всех cookie проекта */
    public static function getOptions(?int $time = null, ?string $samesite = null): array
    {
        return [
            'expires' => $time ?? (time() + 60 * 60 * 24 * 30),
            'path' => '/',
            'domain' => $_ENV['COOKIE_PATH'],
            'secure' => true,
            'httponly' => true,
            'samesite' => $samesite ?? 'Lax',
        ];
    }

    /** Получение текущих cookie из header'а.
     * Разбирает сырую строку Set-Cookie вручную: имена с точками/пробелами не искажаются
     * (в отличие от parse_str), при повторной установке в рамках запроса берётся последнее значение. */
    private static function getCookiesFromHeaders(): array
    {
        $cookies = [];

        foreach (headers_list() as $header) {
            if (!str_starts_with($header, 'Set-Cookie: ')) {
                continue;
            }

            $nameValue = current(explode(';', substr($header, 12), 2));
            $parts = explode('=', $nameValue, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            $value = urldecode(trim($parts[1]));

            if ($value === 'deleted') {
                continue;
            }

            $cookies[$name] = $value;
        }

        return $cookies;
    }

    /** Удаление определённой cookie из headers с сохранением исходных опций остальных.
     * Работает с сырыми строками Set-Cookie: удаляет только целевую, остальные re-emit'ит
     * как есть (не теряя их expires/samesite/domain, в отличие от перевыставления через setcookie). */
    private static function deleteCookieFromHeaders(string $cookieKey): void
    {
        $preserved = [];
        $found = false;

        foreach (headers_list() as $header) {
            if (!str_starts_with($header, 'Set-Cookie: ')) {
                continue;
            }

            $name = trim(explode('=', substr($header, 12), 2)[0]);

            if ($name === $cookieKey) {
                $found = true;
            } else {
                $preserved[] = $header;
            }
        }

        if ($found) {
            header_remove('Set-Cookie');

            foreach ($preserved as $header) {
                header($header, false);
            }
        }
    }
}
