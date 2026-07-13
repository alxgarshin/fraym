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
    /** Создание JWT-токена текущего пользователя */
    public static function generateAuthToken(): string
    {
        $tokenData = [
            "exp" => time() + 3600,
            "id" => CURRENT_USER->id(),
            "sid" => CURRENT_USER->sid(),
            "rights" => CURRENT_USER->getAllRights(),
            "bazecount" => CURRENT_USER->getBazeCount(),
            "block_save_referer" => CURRENT_USER->getBlockSaveReferer(),
            "block_auto_redirect" => CURRENT_USER->getBlockAutoRedirect(),
        ];

        return self::generateJWTAuthToken(["alg" => "HS256", "typ" => "JWT"], $tokenData);
    }

    /** Проверка валидности токена авторизации */
    public static function getAuthTokenPayload(): ?array
    {
        $requestHeaders = getallheaders();
        $authorization = $requestHeaders['Authorization'] ?? '';

        if (!is_string($authorization) || !str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $authToken = trim(substr($authorization, 7));
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
