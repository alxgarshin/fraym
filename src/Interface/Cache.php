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

namespace Fraym\Interface;

interface Cache
{
    public function getFromCache(string $objType, string|int|null $objId, string $idColumnName = 'id'): mixed;

    public function setToCache(string $objType, string|int $objId, mixed $value, string $idColumnName = 'id'): array;
}
