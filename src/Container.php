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

namespace Fraym;

use RuntimeException;

final class Container
{
    private static array $bindings = [];

    public static function bind(string $id, mixed $instance): void
    {
        self::$bindings[$id] = $instance;
    }

    public static function make(string $id): mixed
    {
        if (!array_key_exists($id, self::$bindings)) {
            throw new RuntimeException("Container: no binding for '{$id}'");
        }

        return self::$bindings[$id];
    }

    /** Сброс состояния (persistent workers: Swoole, RoadRunner) */
    public static function reset(): void
    {
        self::$bindings = [];
    }
}
