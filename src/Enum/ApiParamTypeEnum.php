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

enum ApiParamTypeEnum: string
{
    case int = 'int';
    case string = 'string';
    case bool = 'bool';
    case array = 'array';

    /** Cast a value from $_REQUEST to the declared type */
    public function cast(mixed $value): mixed
    {
        return match ($this) {
            self::int => (int) $value,
            self::string => is_array($value) ? '' : (string) $value,
            self::bool => in_array(is_array($value) ? '' : mb_strtolower((string) $value), ['1', 'true', 'on', 'yes'], true),
            self::array => is_array($value) ? $value : [$value],
        };
    }
}
