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

namespace Fraym\Enum;

/** Замкнутый список машинных кодов ошибки ответа */
enum ResponseErrorCodeEnum: string
{
    case validationFailed = 'validation_failed';
    case unauthorized = 'unauthorized';
    case forbidden = 'forbidden';
    case notFound = 'not_found';
    case wrongAction = 'wrong_action';
    case wrongDataFormat = 'wrong_data_format';
    case duplicate = 'duplicate';
    case rateLimited = 'rate_limited';
    case internalError = 'internal_error';

    public function getHttpStatus(): int
    {
        return match ($this) {
            self::validationFailed => 422,
            self::unauthorized => 401,
            self::forbidden => 403,
            self::notFound => 404,
            self::wrongAction, self::wrongDataFormat => 400,
            self::duplicate => 409,
            self::rateLimited => 429,
            self::internalError => 500,
        };
    }

    public function getHttpStatusLine(): string
    {
        return match ($this) {
            self::validationFailed => '422 Unprocessable Entity',
            self::unauthorized => '401 Unauthorized',
            self::forbidden => '403 Forbidden',
            self::notFound => '404 Not Found',
            self::wrongAction, self::wrongDataFormat => '400 Bad Request',
            self::duplicate => '409 Conflict',
            self::rateLimited => '429 Too Many Requests',
            self::internalError => '500 Internal Server Error',
        };
    }
}
