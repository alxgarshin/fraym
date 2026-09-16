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

use DateTimeImmutable;
use Fraym\Element\Item;

/** Interface for models with soft deletion. Requires the model to use the DeletedAtTrait trait or an equivalent, and to set the corresponding visibility rights for the model that exclude (or, e.g., don't, depending on a flag) records with deleted_at IS NOT NULL */
/**
 * @property Item\Hidden $deleted_at
 */
interface DeletedAt
{
    public function getDeletedAtTime(): int|DateTimeImmutable;
}
