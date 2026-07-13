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

namespace Fraym\Exception;

use Throwable;

class DatabaseQueryException extends DatabaseException
{
    /** Подстроки имён параметров (case-insensitive), значения которых маскируются в логах */
    private const SENSITIVE_KEY_SUBSTRINGS = ['password', 'pass', 'refresh_token', 'token', 'csrf', 'secret', 'hash'];

    /** @param array<int|string, mixed> $parameters PDO-параметры запроса — хранятся приватно, наружу отдаются только маскированными */
    public function __construct(
        string $message,
        private readonly array $parameters = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** Параметры запроса с замаскированными чувствительными значениями — безопасно для логов.
     * @return array<int|string, mixed> */
    public function getMaskedParameters(): array
    {
        $masked = [];

        foreach ($this->parameters as $key => $value) {
            $masked[$key] = $this->isSensitiveKey((string) $key) ? '***' : $value;
        }

        return $masked;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = mb_strtolower($key);

        foreach (self::SENSITIVE_KEY_SUBSTRINGS as $substring) {
            if (str_contains($key, $substring)) {
                return true;
            }
        }

        return false;
    }
}
