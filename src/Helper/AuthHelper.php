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

use Exception;
use Fraym\Interface\Helper;
use Fraym\Service\AuthTokenService;

abstract class AuthHelper implements Helper
{
    /** httpOnly cookie with the JWT for the browser SPA (XSS can't read the token) */
    public const AUTH_TOKEN_COOKIE = 'authToken';

    /** Double-submit token cookie for forms of unauthorized users (login/register/reset) */
    public const PRE_AUTH_CSRF_COOKIE = 'csrf_pre_auth';

    /** Create a JWT token for the current user.
     * The payload carries only identifiers: rights/bazecount/block_* are loaded from the DB in auth() —
     * otherwise revoking rights would only take effect when the token expires (1h). */
    public static function generateAuthToken(): string
    {
        $tokenData = [
            "exp" => time() + 3600,
            "id" => CURRENT_USER->id(),
            "sid" => CURRENT_USER->sid(),
        ];

        return self::generateJWTAuthToken(["alg" => "HS256", "typ" => "JWT"], $tokenData);
    }

    /** JWT from the httpOnly cookie (SPA path) */
    public static function getAuthTokenFromCookie(): ?string
    {
        $token = CookieHelper::getCookie(self::AUTH_TOKEN_COOKIE);

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** JWT from the Authorization: Bearer header (external API clients) */
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

    /** Check the validity of the auth token (cookie takes priority over Bearer) */
    public static function getAuthTokenPayload(): ?array
    {
        $authToken = self::getAuthTokenFromCookie() ?? self::getAuthTokenFromBearer();

        return is_null($authToken) ? null : self::validateAuthToken($authToken);
    }

    /** JWT string validation: structure, alg, constant-time signature, exp */
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

        /** Explicit algorithm check — protection against alg substitution */
        if (($tokenHeaders['alg'] ?? null) !== 'HS256') {
            return null;
        }

        /** Constant-time signature check */
        $expectedSignature = DataHelper::base64UrlEncode(
            hash_hmac('SHA256', $headersEncoded . $payloadEncoded, $_ENV['PROJECT_HASH_WORD'], true),
        );

        if (!hash_equals($expectedSignature, $signatureEncoded)) {
            return null;
        }

        /** An expired token is invalid (exp is checked here, not only by the caller) */
        if (!isset($payload['exp']) || !is_int($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    /** A new refresh token for the current device. Tokens of the user's other devices
     *  keep working: the user may have any number of them. */
    public static function generateAndSaveRefreshToken(): void
    {
        CookieHelper::batchSetCookie(['refreshToken' => AuthTokenService::issueRefreshToken(CURRENT_USER->id())]);
    }

    /** Get the refreshToken cookie */
    public static function getRefreshTokenCookie(): ?string
    {
        return CookieHelper::getCookie('refreshToken');
    }

    /** Generate a stateless CSRF token (changes once a day) */
    public static function generateCsrfToken(): string
    {
        $nonce = (int) floor(time() / 86400);

        return hash_hmac(
            'SHA256',
            CURRENT_USER->id() . ':' . CURRENT_USER->sid() . ':' . $nonce,
            $_ENV['PROJECT_HASH_WORD'],
        );
    }

    /** CSRF token validation (accepts today's and yesterday's — seamless day rollover) */
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

    /** Reset the refreshToken cookie */
    public static function removeRefreshTokenCookie(): void
    {
        CookieHelper::batchDeleteCookie(['refreshToken']);
    }

    /** Write the JWT to the httpOnly cookie (lifetime = token lifetime) */
    public static function setAuthTokenCookie(string $token): void
    {
        CookieHelper::batchSetCookie([self::AUTH_TOKEN_COOKIE => $token], time() + 3600);
    }

    /** Reset the JWT cookie */
    public static function removeAuthTokenCookie(): void
    {
        CookieHelper::batchDeleteCookie([self::AUTH_TOKEN_COOKIE]);
    }

    /** Generate a double-submit token: writes the cookie and returns the value for the hidden form field */
    public static function generatePreAuthCsrfToken(): string
    {
        $token = DataHelper::getRandomStringBin2hex(64);
        CookieHelper::batchSetCookie([self::PRE_AUTH_CSRF_COOKIE => $token]);

        return $token;
    }

    /** Validate the form double-submit token: the field value must match the cookie */
    public static function validatePreAuthCsrfToken(): bool
    {
        $cookie = CookieHelper::getCookie(self::PRE_AUTH_CSRF_COOKIE);
        $field = $_REQUEST[self::PRE_AUTH_CSRF_COOKIE] ?? '';

        return is_string($cookie) && $cookie !== '' && is_string($field) && hash_equals($cookie, $field);
    }

    /** Append the project hash to a string */
    public static function addProjectHashWord(string $string): string
    {
        return $string . $_ENV['PROJECT_HASH_WORD'];
    }

    /** Password hashing */
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

    /** Create an auth token */
    private static function generateJWTAuthToken(array $headers, array $payload): string
    {
        $headersEncoded = DataHelper::base64UrlEncode(DataHelper::jsonFixedEncode($headers));
        $payloadEncoded = DataHelper::base64UrlEncode(DataHelper::jsonFixedEncode($payload));
        $signature = hash_hmac('SHA256', $headersEncoded . $payloadEncoded, $_ENV['PROJECT_HASH_WORD'], true);
        $signatureEncoded = DataHelper::base64UrlEncode($signature);

        return $headersEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }
}
