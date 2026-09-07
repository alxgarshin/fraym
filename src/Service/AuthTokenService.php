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

/** Refresh-токены авторизации: у одного пользователя их может быть сколько угодно — по штуке
 *  на устройство. Единый механизм для браузера и внешнего API; когда токен был один на
 *  запись в user, вход с рабочего компьютера выбрасывал из PWA на телефоне.
 *
 *  Здесь же ограничитель частоты попыток входа: он нужен обоим адресам с паролем — и форме
 *  логина, и /login/action=api_token, который отдаёт JWT прямо в теле ответа. */
final class AuthTokenService
{
    public const ACCESS_TOKEN_TTL = 3600;

    public const REFRESH_TOKEN_TTL = 2592000;

    private const RATE_LIMIT_ATTEMPTS = 5;

    private const RATE_LIMIT_WINDOW = 900;

    private const ATTEMPTS_TABLE = 'auth_attempt';

    private const TOKENS_TABLE = 'auth_token';

    /** Новый refresh-токен для ещё одного устройства: чужие токены пользователя не трогаются */
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

    /** Данные пользователя по действующему токену; просроченный токен удаляется.
     *  Новый токен взамен просроченного не выдаётся: это делается только по паролю. */
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

    /** Выход на одном устройстве: остальные сессии пользователя продолжают работать */
    public static function revokeRefreshToken(string $refreshToken): void
    {
        DB->delete(self::TOKENS_TABLE, ['refresh_token' => $refreshToken]);
    }

    /** Выход на всех устройствах: для смены пароля и принудительного отзыва доступа */
    public static function revokeAllRefreshTokens(int|string $userId): void
    {
        DB->delete(self::TOKENS_TABLE, ['user_id' => $userId]);
    }

    /** Сколько секунд осталось до конца окна блокировки; null — попытка разрешена */
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

    /** Ключ попыток: логин вместе с адресом клиента, чтобы блокировка одного адреса
     *  не закрывала вход владельцу учётной записи с другого. */
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
