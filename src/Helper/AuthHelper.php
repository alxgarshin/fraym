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

use DateTime;
use Exception;
use Fraym\Interface\Helper;

abstract class AuthHelper implements Helper
{
    /** httpOnly cookie с JWT для браузерного SPA (XSS не может прочитать токен) */
    public const AUTH_TOKEN_COOKIE = 'authToken';

    /** Cookie double-submit токена для форм неавторизованных пользователей (login/register/reset) */
    public const PRE_AUTH_CSRF_COOKIE = 'csrf_pre_auth';

    /** Создание JWT-токена текущего пользователя.
     * Payload несёт только идентификаторы: rights/bazecount/block_* грузятся из БД при auth() —
     * иначе отзыв прав действовал бы до истечения токена (1ч). */
    public static function generateAuthToken(): string
    {
        $tokenData = [
            "exp" => time() + 3600,
            "id" => CURRENT_USER->id(),
            "sid" => CURRENT_USER->sid(),
        ];

        return self::generateJWTAuthToken(["alg" => "HS256", "typ" => "JWT"], $tokenData);
    }

    /** JWT из httpOnly cookie (SPA-путь) */
    public static function getAuthTokenFromCookie(): ?string
    {
        $token = CookieHelper::getCookie(self::AUTH_TOKEN_COOKIE);

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** JWT из заголовка Authorization: Bearer (внешние API-клиенты) */
    public static function getAuthTokenFromBearer(): ?string
    {
        $authorization = function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '';
        $authorization = $authorization !== '' ? $authorization : ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

        if (!is_string($authorization) || !str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($authorization, 7));

        return $token !== '' ? $token : null;
    }

    /** Проверка валидности токена авторизации (cookie приоритетнее Bearer) */
    public static function getAuthTokenPayload(): ?array
    {
        $authToken = self::getAuthTokenFromCookie() ?? self::getAuthTokenFromBearer();

        return is_null($authToken) ? null : self::validateAuthToken($authToken);
    }

    /** Валидация строки JWT: структура, alg, подпись в постоянном времени, exp */
    public static function validateAuthToken(string $authToken): ?array
    {
        $tokenParts = explode('.', $authToken);

        if (count($tokenParts) !== 3) {
            return null;
        }

        [$headersEncoded, $payloadEncoded, $signatureEncoded] = $tokenParts;

        $headersJson = DataHelper::base64UrlDecode($headersEncoded);
        $payloadJson = DataHelper::base64UrlDecode($payloadEncoded);

        if (is_null($headersJson) || is_null($payloadJson)) {
            return null;
        }

        try {
            $tokenHeaders = DataHelper::jsonFixedDecode($headersJson, true);
            $payload = DataHelper::jsonFixedDecode($payloadJson, true);
        } catch (Exception) {
            return null;
        }

        if (!is_array($tokenHeaders) || !is_array($payload)) {
            return null;
        }

        /** Явная проверка алгоритма — защита от alg-подмены */
        if (($tokenHeaders['alg'] ?? null) !== 'HS256') {
            return null;
        }

        /** Проверка подписи в постоянном времени */
        $expectedSignature = DataHelper::base64UrlEncode(
            hash_hmac('SHA256', $headersEncoded . $payloadEncoded, $_ENV['PROJECT_HASH_WORD'], true),
        );

        if (!hash_equals($expectedSignature, $signatureEncoded)) {
            return null;
        }

        /** Протухший токен невалиден (проверка exp здесь, а не только у вызывающего) */
        if (!isset($payload['exp']) || !is_int($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    /** Генерация криптографичного уникального токена для обновления токена авторизации */
    public static function generateAndSaveRefreshToken(): void
    {
        $refreshToken = DataHelper::getRandomStringBin2hex();
        DB->update('user', ['refresh_token' => $refreshToken, 'refresh_token_exp' => new DateTime('+30 days')], ['id' => CURRENT_USER->id()]);
        CookieHelper::batchSetCookie(['refreshToken' => $refreshToken]);
    }

    /** Получение cookie refreshToken */
    public static function getRefreshTokenCookie(): ?string
    {
        return CookieHelper::getCookie('refreshToken');
    }

    /** Генерация stateless CSRF-токена (меняется раз в сутки) */
    public static function generateCsrfToken(): string
    {
        $nonce = (int) floor(time() / 86400);

        return hash_hmac(
            'SHA256',
            CURRENT_USER->id() . ':' . CURRENT_USER->sid() . ':' . $nonce,
            $_ENV['PROJECT_HASH_WORD'],
        );
    }

    /** Валидация CSRF-токена (принимает сегодняшний и вчерашний — безшовный переход суток) */
    public static function validateCsrfToken(string $token): bool
    {
        if (!CURRENT_USER->isLogged()) {
            return true;
        }

        $nonce = (int) floor(time() / 86400);

        $valid = [
            hash_hmac('SHA256', CURRENT_USER->id() . ':' . CURRENT_USER->sid() . ':' . $nonce, $_ENV['PROJECT_HASH_WORD']),
            hash_hmac('SHA256', CURRENT_USER->id() . ':' . CURRENT_USER->sid() . ':' . ($nonce - 1), $_ENV['PROJECT_HASH_WORD']),
        ];

        return hash_equals($valid[0], $token) || hash_equals($valid[1], $token);
    }

    /** Сброс cookie refreshToken */
    public static function removeRefreshTokenCookie(): void
    {
        CookieHelper::batchDeleteCookie(['refreshToken']);
    }

    /** Запись JWT в httpOnly cookie (срок жизни = сроку жизни токена) */
    public static function setAuthTokenCookie(string $token): void
    {
        CookieHelper::batchSetCookie([self::AUTH_TOKEN_COOKIE => $token], time() + 3600);
    }

    /** Сброс cookie JWT */
    public static function removeAuthTokenCookie(): void
    {
        CookieHelper::batchDeleteCookie([self::AUTH_TOKEN_COOKIE]);
    }

    /** Генерация double-submit токена: пишет cookie и возвращает значение для скрытого поля формы */
    public static function generatePreAuthCsrfToken(): string
    {
        $token = DataHelper::getRandomStringBin2hex(64);
        CookieHelper::batchSetCookie([self::PRE_AUTH_CSRF_COOKIE => $token]);

        return $token;
    }

    /** Валидация double-submit токена формы: значение поля должно совпасть с cookie */
    public static function validatePreAuthCsrfToken(): bool
    {
        $cookie = CookieHelper::getCookie(self::PRE_AUTH_CSRF_COOKIE);
        $field = $_REQUEST[self::PRE_AUTH_CSRF_COOKIE] ?? '';

        return is_string($cookie) && $cookie !== '' && is_string($field) && hash_equals($cookie, $field);
    }

    /** Добавление проеектного хэша к строке */
    public static function addProjectHashWord(string $string): string
    {
        return $string . $_ENV['PROJECT_HASH_WORD'];
    }

    /** Хэширование паролей */
    public static function hashPassword(string $password, bool $usePepper = true): string
    {
        if ($usePepper) {
            $password = self::addProjectHashWord($password);
        }

        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 1 << 17,
            'time_cost'   => 3,
            'threads'     => 1,
        ]);
    }

    /** Создание токена авторизации */
    private static function generateJWTAuthToken(array $headers, array $payload): string
    {
        $headersEncoded = DataHelper::base64UrlEncode(DataHelper::jsonFixedEncode($headers));
        $payloadEncoded = DataHelper::base64UrlEncode(DataHelper::jsonFixedEncode($payload));
        $signature = hash_hmac('SHA256', $headersEncoded . $payloadEncoded, $_ENV['PROJECT_HASH_WORD'], true);
        $signatureEncoded = DataHelper::base64UrlEncode($signature);

        return $headersEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }
}
