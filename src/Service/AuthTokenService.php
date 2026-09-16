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

namespace Fraym\Service;

use Fraym\Enum\OperandEnum;
use Fraym\Helper\{DataHelper, DateHelper};

/** Auth refresh tokens: a user may have any number of them — one per
 *  device. A single mechanism for the browser and the external API; when there was one token per
 *  user record, logging in from a work computer kicked the user out of the PWA on their phone.
 *
 *  This is also where the login attempt rate limiter lives: both password endpoints need it — the login
 *  form and /login/action=api_token, which returns the JWT right in the response body. */
final class AuthTokenService
{
    public const ACCESS_TOKEN_TTL = 3600;

    public const REFRESH_TOKEN_TTL = 2592000;

    private const RATE_LIMIT_ATTEMPTS = 5;

    private const RATE_LIMIT_WINDOW = 900;

    private const ATTEMPTS_TABLE = 'auth_attempt';

    private const TOKENS_TABLE = 'auth_token';

    /** A new refresh token for one more device: the user's other tokens are left untouched */
    public static function issueRefreshToken(int|string $userId): string
    {
        $now = DateHelper::getNow();
        $refreshToken = DataHelper::getRandomStringBin2hex();

        DB->insert(self::TOKENS_TABLE, [
            'user_id' => $userId,
            'refresh_token' => $refreshToken,
            'refresh_token_exp' => $now + self::REFRESH_TOKEN_TTL,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $refreshToken;
    }

    /** User data by a valid token; an expired token is deleted.
     *  No new token is issued in place of an expired one: that only happens with a password. */
    public static function findUserByRefreshToken(string $refreshToken): ?array
    {
        $record = DB->select(self::TOKENS_TABLE, ['refresh_token' => $refreshToken], true);

        if (!$record) {
            return null;
        }

        if ((int) $record['refresh_token_exp'] <= DateHelper::getNow()) {
            DB->delete(self::TOKENS_TABLE, ['id' => $record['id']]);

            return null;
        }

        $userData = DB->select('user', ['id' => $record['user_id']], true);

        if (!$userData) {
            DB->delete(self::TOKENS_TABLE, ['id' => $record['id']]);

            return null;
        }

        return $userData;
    }

    public static function prolongRefreshToken(string $refreshToken): void
    {
        $now = DateHelper::getNow();

        DB->update(
            self::TOKENS_TABLE,
            [
                'refresh_token_exp' => $now + self::REFRESH_TOKEN_TTL,
                'updated_at' => $now,
            ],
            ['refresh_token' => $refreshToken],
        );
    }

    /** Log out on one device: the user's other sessions keep working */
    public static function revokeRefreshToken(string $refreshToken): void
    {
        DB->delete(self::TOKENS_TABLE, ['refresh_token' => $refreshToken]);
    }

    /** Log out on all devices: for password changes and forced access revocation */
    public static function revokeAllRefreshTokens(int|string $userId): void
    {
        DB->delete(self::TOKENS_TABLE, ['user_id' => $userId]);
    }

    /** How many seconds are left until the end of the lockout window; null — the attempt is allowed */
    public static function getRetryAfter(string $login): ?int
    {
        $record = self::getAttemptRecord($login);

        if (!$record) {
            return null;
        }

        $windowEnd = (int) $record['window_started_at'] + self::RATE_LIMIT_WINDOW;

        if ((int) $record['attempts'] < self::RATE_LIMIT_ATTEMPTS || $windowEnd <= DateHelper::getNow()) {
            return null;
        }

        return $windowEnd - DateHelper::getNow();
    }

    public static function registerFailedAttempt(string $login): void
    {
        $now = DateHelper::getNow();
        $record = self::getAttemptRecord($login);

        if (!$record || (int) $record['window_started_at'] + self::RATE_LIMIT_WINDOW <= $now) {
            $data = [
                'attempt_key' => self::getAttemptKey($login),
                'attempts' => 1,
                'window_started_at' => $now,
                'updated_at' => $now,
            ];

            if ($record) {
                DB->update(self::ATTEMPTS_TABLE, $data, ['id' => $record['id']]);
            } else {
                $data['created_at'] = $now;
                DB->insert(self::ATTEMPTS_TABLE, $data);
            }

            return;
        }

        DB->update(
            self::ATTEMPTS_TABLE,
            [
                'attempts' => (int) $record['attempts'] + 1,
                'updated_at' => $now,
            ],
            ['id' => $record['id']],
        );
    }

    public static function clearAttempts(string $login): void
    {
        DB->delete(self::ATTEMPTS_TABLE, ['attempt_key' => self::getAttemptKey($login)]);
    }

    /** Attempts key: the login together with the client address, so that locking out one address
     *  doesn't block the account owner from logging in from another. */
    private static function getAttemptKey(string $login): string
    {
        return hash('sha256', mb_strtolower($login) . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    private static function getAttemptRecord(string $login): array|false
    {
        self::clearExpiredAttempts();

        return DB->select(self::ATTEMPTS_TABLE, ['attempt_key' => self::getAttemptKey($login)], true);
    }

    private static function clearExpiredAttempts(): void
    {
        DB->delete(
            self::ATTEMPTS_TABLE,
            [['window_started_at', DateHelper::getNow() - self::RATE_LIMIT_WINDOW, [OperandEnum::LESS]]],
        );
    }
}
