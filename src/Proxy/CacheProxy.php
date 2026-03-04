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

namespace Fraym\Proxy;

use Fraym\Container;
use Fraym\Interface\Cache;

/**
 * Прокси-объект для константы CACHE.
 * Делегирует все вызовы в Container::make('cache').
 */
final class CacheProxy implements Cache
{
    public function getFromCache(string $objType, string|int|null $objId, string $idColumnName = 'id'): mixed
    {
        return Container::make('cache')->getFromCache($objType, $objId, $idColumnName);
    }

    public function setToCache(string $objType, string|int $objId, mixed $value, string $idColumnName = 'id'): array
    {
        return Container::make('cache')->setToCache($objType, $objId, $value, $idColumnName);
    }
}
