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

use Fraym\Interface\Cache;

final class CacheService implements Cache
{
    //TODO: align with https://github.com/php-fig/cache

    private $_CACHE = [
        '_LOCALE' => ['id' => [0 => []]],
    ];

    /** Create or get the cache into a constant. Default: CACHE */
    public static function getInstance(string $constName = 'CACHE'): self
    {
        if (defined($constName)) {
            return constant($constName);
        } else {
            return self::forceCreate();
        }
    }

    /** Forced cache creation */
    public static function forceCreate(): self
    {
        return new self();
    }

    /** Get a variable from the cache */
    public function getFromCache(string $objType, string|int|null $objId, string $idColumnName = 'id'): mixed
    {
        if (isset($this->_CACHE[$objType])) {
            if (isset($this->_CACHE[$objType][$idColumnName])) {
                if (is_null($objId)) {
                    return $this->_CACHE[$objType][$idColumnName];
                } elseif (isset($this->_CACHE[$objType][$idColumnName][$objId])) {
                    return $this->_CACHE[$objType][$idColumnName][$objId];
                }
            }
        }

        return null;
    }

    /** Set a variable in the cache */
    public function setToCache(string $objType, string|int $objId, mixed $value, string $idColumnName = 'id'): array
    {
        $this->_CACHE[$objType][$idColumnName][$objId] = $value;

        return $this->_CACHE;
    }
}
